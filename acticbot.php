<?php
/**
 * @copyright Copyright (C) 2016 by Andreas Bank, andreas.mikael.bank@gmail.com
 *
 * @brief A script that automatically registers you to a desired course at
 * Actic.se.
 */

 /*
  * TODO: Extract the curl part and make it use pthreads
  */

date_default_timezone_set('Europe/Stockholm');

class ActicBot {
  private $uname = '';
  private $passwd = '';
  private $verbose = false;
  private $full_url = null;
  private $referer = null;
  private $post_data = null;
  /*
   * Possible types for $proxy_type:
   * CURLPROXY_HTTP
   * CURLPROXY_SOCKS4
   * CURLPROXY_SOCKS5
   * CURLPROXY_SOCKS4A
   * CURLPROXY_SOCKS5_HOSTNAME
   */
  private $proxy_type = CURLPROXY_SOCKS5;
  //private $proxy_addr = "https://wwwproxy.se.axis.com:3128";
  //private $proxy_addr = "localhost";
  private $proxy_port = 8090;
  private $proxy_user = null;
  private $proxy_pass = null;
  private $use_ff_agent = true;
  private $use_http_1_0 = true;
  private $request = array(
    'headers' => array(),
    'cookies' => array(),
    'body' => null
  );
  private $response = array(
    'headers' => array(),
    'cookies' => array(),
    'body' => null
  );
  private $logged_in = false;
  private $fetch_time = null;
  private $refresh_interval = 5;
  private $ch = null;

  function __construct($uname, $passwd) {
    $this->uname = $uname;
    $this->passwd = $passwd;
  }

  private function generate_cookies_header($cookies_array) {
    $cookies = null;
    foreach ($cookies_array as $key => $val) {
      $cookies = sprintf("%s%s=%s",
          !empty($cookies) ? sprintf("%s; ", $cookies) : '', $key, $val);
    }

    return $cookies;
  }

  private function generate_headers($headers_array) {
    $headers = array();
    if ($headers_array != null) {
      foreach ($headers_array as $key => $val) {
        $headers[] = sprintf("%s: %s", $key, $val);
      }
    }

    return $headers;
  }

  public function prepare($full_url, $referer, $post_data, $headers, $cookies) {
    $this->full_url = $full_url;
    $this->referer = $referer;
    $this->post_data = $post_data;
    $this->request['headers'] = $this->generate_headers($headers);
    $this->request['cookies'] = $cookies ? $cookies : array();

    $this->ch = curl_init();

    $url = parse_url($this->full_url);

    $this->request['headers'] = array_merge($this->request['headers'], array(
      sprintf("%s %s%s%s HTTP/1.0", ($this->post_data ? 'POST' : 'GET'),
          $url['path'], isset($url['query']) ? sprintf("?%s",
          $url['query']) : '', isset($url['fragment']) ? sprintf("#%s",
          $url['fragment']) : ''),
      sprintf("Host: %s%s", $url['host'],
          isset($url['port']) && !empty($url['port']) ?
          sprintf(":%s", $url['port']) : ''),
      'Cache-Control: max-age=0',
      !empty($this->post_data) ?
          'Content-type: application/x-www-form-urlencoded' :
          'Content-type: text/html;charset=UTF-8',
      'Connection: close'
    ));

    curl_setopt($this->ch, CURLOPT_URL, $this->full_url);

    if (isset($this->proxy_addr) && !empty($this->proxy_addr)) {
    /* Set proxy options if a proxy address is given */

      curl_setopt($this->ch, CURLOPT_PROXY, $this->proxy_addr);
      if (isset($this->proxy_port) && !empty($this->proxy_port)) {
        curl_setopt($this->ch, CURLOPT_PROXYPORT, $this->proxy_port);
      }
      curl_setopt($this->ch, CURLOPT_PROXYTYPE, $this->proxy_type);
      if (isset($this->proxy_use) && !empty($this->proxy_user) &&
          isset($this->proxy_pass) && !empty($this->proxy_pass)) {
        curl_setopt($this->ch, CURLOPT_PROXYUSERPWD, sprintf("%s:%s",
            $this->proxy_user, $this->proxy_pass));
      }
      curl_setopt($this->ch, CURLOPT_HTTPPROXYTUNNEL, true);
    }

    if ($this->verbose) {
      curl_setopt($this->ch, CURLOPT_VERBOSE, true);
    }
    curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($this->ch, CURLOPT_HEADER, true);
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
    if ($this->use_http_1_0) {
      curl_setopt($this->ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
    }

    if (isset($this->referer) && !empty($this->referer)) {
      curl_setopt($this->ch, CURLOPT_REFERER, $this->referer);
    }
    if ($this->use_ff_agent) {
      curl_setopt($this->ch, CURLOPT_USERAGENT, sprintf("%s%s",
          'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 ',
          '(KHTML, like Gecko) Chrome/48.0.2564.109 Safari/537.36'));
    }
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->request['headers']);
    if (isset($this->request['cookies']) && !empty($this->request['cookies'])) {
      curl_setopt($this->ch, CURLOPT_COOKIE,
          $this->generate_cookies_header($this->request['cookies']));
    }

    /* Chose sending method; GET or POST */
    if (!empty($this->post_data)) {
      curl_setopt($this->ch, CURLOPT_POST, true);
      curl_setopt($this->ch, CURLOPT_POSTFIELDS,
          http_build_query($this->post_data));
    }

  } /* prepare() */

  private function print_headers($type) {
    $hs = $type == 0 ? $this->request['headers'] : $this->response['headers'];

    printf("\n%s headers:\n", ($type == 0) ? 'Request' :
        'Response');

    foreach ($hs as $key => $val) {
      printf("'%s' = '%s'\n", $key, $val);
    }
    printf("\n");
  }

  public function print_request_headers() {
    $this->print_headers(0);
  }

  public function print_response_headers() {
    $this->print_headers(1);
  }

  private function parse_cookies($cookies_str) {
    $cookies = array();
    $cookies_split = explode(';', $cookies_str);
    foreach ($cookies_split as $cs) {
      $keyval = explode('=', $cs);
      if (empty($keyval) || empty($keyval[0]) || !isset($keyval[1]) ||
          empty($keyval[1])) {
        continue;
      }
      $cookies[trim($keyval[0])] = trim($keyval[1]);
    }

    return $cookies;
  }

  public function get_response_cookie($key) {
    $c = $this->response['cookies'];

    foreach ($c as $k => $v) {
      if ($k == $key) {
        return $v;
      }
    }

    throw new Exception("Key '%s' not found", $key);
  }

  public function get_all_response_cookies() {
    return $this->response['cookies'];
  }

  private function print_cookies($type) {
    $c = $type == 0 ? $this->request['cookies'] : $this->response['cookies'];

    printf("\n%s cookies:\n", ($type == 0) ? 'Request' :
        'Response');
    foreach ($c as $k => $v) {
      printf("'%s' = '%s'\n", $k, $v);
    }
    printf("\n");
  }

  public function print_request_cookies() {
    $this->print_cookies(0);
  }

  public function print_response_cookies() {
    $this->print_cookies(1);
  }

  public function print_response_body() {
    printf("%s\n", $this->response['body']);
  }

  public function clear_refresh() {
    $this->fetch_time = null;
  }

  private function need_refresh() {
    if ($this->verbose) {
      printf("Minutes passed: %d\n", (time() - $this->fetch_time) / 60);
    }

    if ($this->fetch_time == null ||
        (int)((time() - $this->fetch_time) / 60) >
        $this->refresh_interval) {
      return true;
    }

    return false;
  }

  private function call($full_url, $referer, $post_data, $headers,
      $inherit_cookies, $cookies) {
    if (($this->full_url == $full_url) && !$this->need_refresh()) {
      if ($this->verbose) {
        printf("Skipping call...\n");
      }
      return;
    }

    if (!$this->logged_in) {
      if ($this->verbose) {
        printf("Not logged in.");
      }
      $this->login();
    }

    if ($inherit_cookies) {
      if (($cookies != null) || (count($cookies) > 0)) {
        $cookies = array_merge($cookies, $this->response['cookies']);
      } else {
        $cookies = $this->response['cookies'];
      }
    }

    $this->prepare($full_url, $referer, $post_data, $headers, $cookies);

    if (!($result = curl_exec($this->ch))) {
      throw new Exception("%s", curl_error($this->ch));
    }
    $this->fetch_time = time();

    $header_size = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
    $headers = substr($result, 0, $header_size);
    $split_headers = explode("\n", $headers);
    foreach ($split_headers as $h) {
      $keyval = explode(': ', $h);
      if (empty($keyval) || empty($keyval[0]) || !isset($keyval[1]) ||
          empty($keyval[1])) {
        continue;
      }
      $this->response['headers'][trim($keyval[0])] = trim($keyval[1]);
      if ($keyval[0] == 'Set-Cookie') {
        $this->response['cookies'] = $this->parse_cookies($keyval[1]);
      }
    }
    $this->response['body'] = substr($result, $header_size);
    if ($this->verbose) {
      printf("Result:\n%s\n", $this->response['body']);
    }
    curl_close($this->ch);
  }

  private function parse_bookings($data) {
    $pattern = '/<tr class="bok">';
    $pattern = sprintf("%s%s", $pattern,
        '\s*<td data-title="Datum">\s*<h5>(?<date>.*)<\/h5>\s*<\/td>');
    $pattern = sprintf("%s%s", $pattern,
        '\s*<td data-title="Tid">\s*<h5>(?<time>.*)<\/h5>\s*<\/td>');
    $pattern = sprintf("%s%s", $pattern,
        '\s*<td data-title="Pass">\s*<h5>(?<pass>.*)<\/h5>\s*<\/td>');
    $pattern = sprintf("%s%s%s", $pattern,
        '\s*<td data-title="Anläggning">\s*<h5>.*>(?<center_name>.*)<.*<\/h5>\s*',
        '<\/td>');
    $pattern = sprintf("%s%s", $pattern,
        '\s*<td data-title="Instruktör">\s*<h5>(?<instructor>.*)<\/h5>\s*<\/td>');
    $pattern = sprintf("%s%s", $pattern,
        '.*cancelBooking\(\s*(?<participation_id>\d+),\s*(?<center_id>\d+),\s*(?<activity_id>\d+),');
    $pattern = sprintf("%s.*<\/tr>/Us", $pattern);
    $matches = null;
    $result = preg_match_all($pattern, $data, $matches);

    $bookings = array();

    if ($result != false && $result > 0) {
      for ($i = 0; $i < $result; $i++) {
        $bookings[] = array('date' => $matches['date'][$i],
            'time' => $matches['time'][$i],
            'pass' => $matches['pass'][$i],
            'center_name' => $matches['center_name'][$i],
            'instructor' => $matches['instructor'][$i],
            'participation_id' => $matches['participation_id'][$i],
            'center_id' => $matches['center_id'][$i],
            'activity_id' => $matches['activity_id'][$i]);
      }
    }

    return $bookings;
  }

  private function login() {
    $this->logged_in = true;
    $this->clear_refresh();
    if ($this->verbose) {
      printf("Logging in...");
    }
    $this->call('http://www.actic.se/mina-bokningar/',
        'http://www.actic.se/log-in/',
        array('actic_username' => $this->uname,
        'actic_pincode' => $this->passwd), null, false, null);
    $this->clear_refresh();
    $this->call('http://www.actic.se/mina-bokningar/',
        'http://www.actic.se/log-in/', null, null, true, null);
    if (strstr($this->response['body'], '>Logga in</a>') !== false) {
      $this->logged_in = false;
      throw new Exception('Failed to login');
    } else {
      if ($this->verbose) {
        printf("Login successfull.");
      }
    }
  }

  public function get_bookings() {
    $this->call('http://www.actic.se/mina-bokningar/',
        'http://www.actic.se/log-in/', null, null, true, null);
    $bookings = $this->parse_bookings($this->response['body']);

    return $bookings;
  }

  public function get_center_ids() {
    $this->call('http://www.actic.se/mina-bokningar/',
        'http://www.actic.se/log-in/', null, null, true, null);
  }

  public function unbook($booking) {
    if (isset($booking['participation_id']) &&
        isset($booking['center_id'])) {
      $this->call('http://www.actic.se/wp-admin/admin-ajax.php?action=cancel_booking&participation_id=300370&center_id=24',
          'http://www.actic.se/mina-bokningar/', null, null, true, null);
    } else {
      throw new Exception('Missing parameters');
    }
    if (strstr($this->repsonse['body'], 'success:true') != false) {
      throw new Exception(
          "Failed to unbook participation_id '%s' at center '%s'",
          $booking['participation_id'], $booking['center_id']);
    }
  }

  private function parse_trainings($data) {
    $pattern_pass = '/span class="textcontentpasses">\s*(?<time>\d+:\d+ - \d+:\d+?).*span class="text-primary">(?<pass>.*)<\/span/Us';
    $pattern_instructor = '/Instruktör<\/strong> <br>(?<instructor>.*)<\/p>/U';
    $pattern_info = '/visible-xs.*getBookingDetails\((?<booking_id>\d+), (?<activity_id>\d+), (?<center_id>\d+), \'(?<date>.*)\',\s*\$\(this\)\);/U';

    $result_pass = preg_match_all($pattern_pass, $data, $matches_pass);
    $result_instructor = preg_match_all($pattern_instructor, $data,
        $matches_instructor);
    $result_info = preg_match_all($pattern_info, $data, $matches_info);

    $trainings = array();

    if (($result_pass != false) && ($result_pass > 0) &&
        ($result_instructor != false) && ($result_instructor == $result_pass) &&
        ($result_info != false) && ($result_info ==  $result_pass)) {
      for ($i = 0; $i < $result_info; $i++) {
        $trainings[] = array(
            'time' => $matches_pass['time'][$i],
            'pass' => $matches_pass['pass'][$i],
            'instructor' => $matches_instructor['instructor'][$i],
            'booking_id' => $matches_info['booking_id'][$i],
            'activity_id' => $matches_info['activity_id'][$i],
            'center_id' => $matches_info['center_id'][$i],
            'date' => $matches_info['date'][$i],
            );
      }
    }

    return $trainings;
  }

  public function get_trainings($center_id, $date) {
    $this->call(
      sprintf("http://www.actic.se/snabbsida-for-bokning/?quickbook_center=%d&quickbook_day=%s",
      $center_id, $date), 'http://www.actic.se/mina-bokningar/', null, null,
      true, null);
    $trainings = $this->parse_trainings($this->response['body']);

    return $trainings;
  }

  private function parse_centers($data) {
    $centers = array();
    $pattern = '/Välj anläggning\.\.\.<\/option>\s*(.+)\s*<\/select>/s';
    $matches = null;

    $result = preg_match($pattern, $data, $matches);
    if ($result == 1) {
      $data = $matches[1];
      $pattern = '/<option value="(?<center_id>\d+)"\s*>\s*(?<center_name>.+)\s*<\/option>/U';
      $result = preg_match_all($pattern, $data, $matches);

      if ($result != false && $result > 0) {
        for ($i = 0; $i < $result; $i++) {
          $centers[] = array(
              'center_id' => $matches['center_id'][$i],
              'center_name' => $matches['center_name'][$i]
              );
        }
      }
    }

    return $centers;
  }

  public function get_centers() {
    $this->call('http://www.actic.se/mina-bokningar/',
      'http://www.actic.se/log-in/', null, null, true, null);
    $centers = $this->parse_centers($this->response['body']);

    return $centers;
  }

  private function get_next_friday() {
  }

  public function is_booked($day, $time, $pass, $center_id, $instructor) {
    $next_day = strtotime(sprintf("next %s", $day));

    if ($next_day === false) {
      throw new Exception("Erroneous day '%s'.\n", $day);
    }

    $next_day = date('Y-m-d', strtotime(sprintf("next %s", $day)));

    $bookings = $this->get_bookings();

    foreach ($bookings as $booking) {
      if (($booking['date'] == $next_day) &&
          ($booking['time'] == $time) &&
          ($booking['pass'] == $pass) &&
          ($booking['center_id'] == $center_id) &&
          (empty($instructor) || ($booking['instructor'] == $instructor))) {
        return true;
      }
    }

    return false;
  }

  public function book($day, $time, $pass, $center_id, $instructor) {
    $next_day = strtotime(sprintf("next %s", $day));

    if ($next_day === false) {
      throw new Exception("Erroneous day '%s'.\n", $day);
    }

    $next_day = date('Y-m-d', strtotime(sprintf("next %s", $day)));
    if ($this->verbose) {
      printf("Weekday calculated to '%s'\n", $next_day);
    }

    $trainings= $this->get_trainings($center_id, $next_day);

    foreach ($trainings as $training) {
      if (($training['date'] == $next_day) &&
          ($training['time'] == $time) &&
          ($training['pass'] == $pass) &&
          ($training['center_id'] == $center_id) &&
          ((empty($instructor)) || ($training['instructor'] == $instructor))) {

        $this->call(sprintf(
            "http://www.actic.se/wp-admin/admin-ajax.php?action=confirm_booking&center_id=%s&booking_id=%s&activity_id=%s",
            $training['center_id'], $training['booking_id'],
            $training['activity_id']), 'http://www.actic.se/log-in/', null,
            null, true, null);
        /* TODO: Return more specific codes */
        return true;
      }
    }

    throw new Exception("No such booking exists.");
  }

} /* class ActiveBot */
$acticbot = new ActicBot('andreas.mikael.bank@gmail.com', 'somepass');
/*
$bookings = $acticbot->get_bookings();
var_dump($bookings);
$trainings= $acticbot->get_trainings(24, '2016-02-26');
var_dump($trainings);
$centers = $acticbot->get_centers();
var_dump($centers);
*/
try {
  $acticbot->book('friday', '17:00 - 17:55', 'Functional Toning by Actic',
      24, 'Michail Triantafyllidis');
  $acticbot->print_response_body();
  $acticbot->book('monday', '19:30 - 20:15', 'Functional Toning by Actic',
      77, 'Ingrid Guldbrand');
  $acticbot->print_response_body();
}
catch(Exception $e) {
  printf("An error ocurred: %s\n", $e->getMessage());
}

/*
Delphinbadet id: 24
Hogevall id: 77
Olympen id: 175

List bookings (Mina-bokningar):
grep:
$pattern = '';
preg_match();
              <tr class="bok">
                <td data-title="Datum"><h5>2016-02-26</h5></td>
                <td data-title="Tid"><h5>17:00 - 17:55</h5></td>
                <td data-title="Pass"><h5>Functional Toning by Actic</h5></td>
                <td data-title="Anläggning"><h5><a class="bluelightcolor" href="http://www.actic.se/club/lund-delphinenbadet/">Lund, Lund Delphinenbadet</a></h5></td>
                <td data-title="Instruktör"><h5>Michail Triantafyllidis</h5></td>
                <td class="table-btn">
                                    <button class="btn btn-md btn-block btn-danger pull-right" href="#" onclick="cancelBooking(300038, 24, 46462, '2016-02-26',$(this));">Avboka</button>
                </td>
              </tr>

List bookings:
http://www.actic.se/snabbsida-for-bokning/?quickbook_center=24&quickbook_day=2016-02-26
reggex -> 'getBookingDetails(46003, 6409, 24, '2016-03-01',$(this));'
                        booking_id, activity_id, center_id,, date

(trivial step, can be completely skipped) Start book:
http://www.actic.se/wp-admin/admin-ajax.php?action=get_booking_details&booking_id=46560&activity_id=15222&center_id=24&date=2016-02-26

Confirm book:
http://www.actic.se/wp-admin/admin-ajax.php?action=confirm_booking&center_id=24&booking_id=46560&activity_id=15222
Result:
{"success":true,"is_valid_user":true,"participation":{"participation_id":301324,"booking_id":46147,"center_id":24,"person_id":33601,"list_index":9,"waiting_list_index":null,"date":"2016-03-01","start_time":"20:00","end_time":"20:55","instructor_name":"Becky Kang","instructor_names":"Becky Kang","room_name":"Grupptr\u00e4ning","state":"BOOKED"},"center_id":"24","activity_id":"4"}

Un-book:
http://www.actic.se/wp-admin/admin-ajax.php?action=cancel_booking&participation_id=300370&center_id=24
Result:
{"success":true,"is_valid_user":true,"participation":true}

List class types at a center:
http://www.actic.se/wp-admin/admin-ajax.php?action=get_activities&center=24
Result:
{"success":true,"center":"24","acts":[{"id":203,"name":"Bootcamp"},{"id":1,"name":"Grupptr\u00e4ning"},{"id":2,"name":"Spinning"}]}

*/
?>
