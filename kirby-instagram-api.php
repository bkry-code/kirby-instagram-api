<?php

function instagramapi_isJSON($string) {
  $array = json_decode($string, true);
  return !empty($string) && is_string($string) && is_array($array) && !empty($array) && json_last_error() == 0;
}

function instagramapi_simpleCurl($url, $method='GET', $data = null, $json = false) {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

  if($method == 'POST' && $data) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: multipart/form-data; charset=utf-8; boundary=__X_PAW_BOUNDARY__",
    ]);
    $body = $json ? json_encode($data) : $data;
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  }

  $resp = curl_exec($ch);

  if($resp === false) {
    die('Error: "' . curl_error($ch) . '" - Code: ' . curl_errno($ch));
    return false;
  } else {
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      if($c == 200) {
        return $resp;
      } else {
        echo "Response HTTP Status Code : " . $c;
      echo "\nResponse HTTP Body : " . $resp;
        $resp = false;
      }
  }

  curl_close($ch);
  return $resp;
}

function instagramapi_generate_sig($endpoint, $params, $secret) {
  $sig = $endpoint;
  ksort($params);
  foreach ($params as $key => $val) {
    $sig .= "|$key=$val";
  }
  return hash_hmac('sha256', $sig, $secret, false);
}

function instagramapi($user, $endpoint, $snippet = '', $params = []) {

  // SNIPPET
  if(!$snippet || strlen(trim($snippet)) == 0) {
    $snippet = null;
  }
  
  // SECRET
  $secret = c::get('plugin.instagram-api.client-secret', false);
  if(!$secret) return 'Missing Instagram API Secret.';

  // ENDPOINT
  if(gettype($endpoint) != 'string' || strlen(trim($endpoint)) <= 1) return 'Invalid Endpoint.';
  $endpoint = trim($endpoint);
  if(substr($endpoint, 0, 1) != '/') $endpoint = '/'.$endpoint;
  if(substr($endpoint, -1) != '/') $endpoint = $endpoint.'/';

  // PARAMS
  if(!$params || gettype($params) != 'array') {
    $params = array();
  }

  // USER
  $userInstagram = null;
  if(!is_a($user, 'User') && gettype($user) == 'string') {
    $user = site()->user($user);
  }
  if($user) {
    if($iad = $user->instagramapi()) {
      $iad = explode(' ', $iad); // see $account_ID_Token
      if(count($iad) == 3) {
        $userInstagram = [
          'account'   => $iad[0],
          'userid'    => $iad[1],
          'token'     => $iad[2],
          ];
      }
    }
    if(!$userInstagram) {
      return 'User is missing Instagram authorization.';
    }
  } else {
    return 'Invalid User.';
  }

  $params = array_merge($params, ['access_token' => $userInstagram['token']]);
  $sig = instagramapi_generate_sig($endpoint, $params, $secret);
  if(!$sig) {
    return 'hash_hmac algo sha256 unknown.';
  } else if(c::get('plugin.instagram-api.signedrequests', false)) {
    $sig = "&sig=".$sig;
  } else {
    $sig = ' ';
  }

  $url  = [
    c::get('plugin.instagram-api.endpoint-root', "https://api.instagram.com/v1"),
    "/users/".$userInstagram['userid'],
    $endpoint,
    "?access_token=".$userInstagram['token'],
    $sig,
  ];
  $url = trim(implode('', $url));

  $resp = instagramapi_simpleCurl($url);  
  if($resp !== false && instagramapi_isJSON($resp)) {
    $json = json_decode($resp, c::get('plugin.instagram-api.json_decode.assoc', true));

    //a::show($json);

    // CATCH ERRORS or return JSON as array
    if($meta = a::get($json, 'meta')) {
      $metaCode = intval(a::get($meta, 'code'));
      if($metaCode == 200) {
        if($snippet) {
          return snippet($snippet, [
              'snippetByTag'  => true, 
              'result'        => $json
            ], true);
        } else {
          return $json;
        }

      } else {
        return 'Error Code: '. $metaCode;
      }
    }

    if($code = a::get($json, 'code' , null)) {
      if(intval($code) == 200) {
        return $json;
      } else {
        return implode('.<br>', [
            'code: '          . a::get($json, 'code', ''),
            'error_type: '    . a::get($json, 'error_type', ''),
            'error_message: ' . a::get($json, 'error_message', ''),
          ]);
      }
    }
  }
  return 'Unknown Error.';
}

/****************************************
  SNIPPETS
 ***************************************/

$snippets = new Folder(__DIR__ . '/snippets');
foreach ($snippets->files() as $file) {
  if($file->extension() == 'php') {
    $kirby->set('snippet', $file->name(), $file->root());  
  }
}

/****************************************
  FIELDS
 ***************************************/

$kirby->set('field', 'instagramapidata', __DIR__ . '/fields/instagramapidata');

/****************************************
  BLUEPRINTS
 ***************************************/

$blueprints = new Folder(__DIR__ . '/blueprints/fields');
foreach ($blueprints->files() as $file) {
  if($file->extension() == 'yml') {
    $kirby->set('blueprint', 'fields/'.$file->name(), $file->root());  
  }
}

/****************************************
  EMAIL SERVICE
 ***************************************/

email::$services['instagramapi-html'] = function($email) {
  $headers = array(
    'From: ' . $email->from,
    'Reply-To: ' . $email->replyTo,
    'Return-Path: ' . $email->replyTo,
    'Message-ID: <' . time() . '-' . $email->from . '>',
    'X-Mailer: PHP v' . phpversion(),
    'Content-Type: text/html; charset=utf-8',
    'Content-Transfer-Encoding: 8bit',
  );
  if(a::get($email->options, 'bcc') && v::email($email->options['bcc'])) { // add bcc
    array_push($headers, 'Bcc: ' . $email->options['bcc']);
  }

  ini_set('sendmail_from', $email->from);
  $send = mail($email->to, str::utf8($email->subject), str::utf8($email->body), implode(PHP_EOL, $headers));
  ini_restore('sendmail_from');

  if(!$send) {
    throw new Error('The email could not be sent');
  }
};

/****************************************
  ROUTES
 ***************************************/

$kirby->set('route', 
  array(
      'pattern' => 'kirby-instagram-api/redirect',
      'action' => function() {

        $error = 'unknown';
        $clientid = c::get('plugin.instagram-api.client-id', '');
        $clientsecret = c::get('plugin.instagram-api.client-secret', '');
        if(strlen($clientid) != 32 || strlen($clientsecret) != 32) {
          return go(site()->homepage()->url() . '#error=instagram-settings');
        }

        // https://www.instagram.com/developer/authentication/
        // STEP 2: http://your-redirect-uri?code=CODE
        // get code and possible errors from $all
        // http://your-redirect-uri?code=CODE
        // http://your-redirect-uri?error=access_denied&error_reason=user_denied&error_description=The+user+denied+your+request
        
        parse_str(parse_url(server::get('request_uri'), PHP_URL_QUERY), $params);
        $code = a::get($params, 'code');

        $username = a::get($params, 'u');
        $user = site()->user($username);
        if(!$username || !$user || !$code || a::get($params, 'error')) {
          //return response::json($params, 400);
          return go(site()->homepage()->url() . '#invalid-query');
        }
        
        // STEP 3: curl the token
        // instagram api
        $redirect = implode([
          site()->url(),
          '/kirby-instagram-api/redirect',
          '?u=' . $username,
        ]);
        $request = [
            'client_id'     => $clientid,
            'code'          => $code,
            'redirect_uri'  => $redirect, // urldecode()?
            'client_secret' => $clientsecret,
            'grant_type'    => 'authorization_code'
        ];
        $response = instagramapi_simpleCurl('https://api.instagram.com/oauth/access_token', 'POST', $request);
        if($response !== false && instagramapi_isJSON($response)) {
          $json = json_decode($response);
          /*
          {
            "access_token": "fb2e77d.47a0479900504cb3ab4a1f626d174d2d",
            "user": {
                "id": "1574083",
                "username": "snoopdogg",
                "full_name": "Snoop Dogg",
                "profile_picture": "..."
            }
          }
         */
          if(isset($json->access_token) ** isset($json->user)) {
            // if success update the field with space-seperated: username id access_token
            try {

              $account_ID_Token = implode(' ', [
                $json->user->username,
                $json->user->id,
                $json->access_token,
              ]);

              $user->update([
                'instagramapi' => $account_ID_Token,
              ]);

              $senderemail = c::get('plugin.instagram-api.email.from', c::get('email.from'), $user->email());

              // send email to user
              $emailKirby = email([
                'to'      => $user->email(),
                'from'    => $senderemail,
                'subject' => c::get('plugin.instagram-api.email-success.subject', c::get('email.subject', 'Kirby CMS InstagramAPI Plugin: Authorization Email')),
                'body'    => snippet(c::get('plugin.instagram-api.email-success.body-snippet', 'instagramapi-email-success-body'),[
                    'user'        => $user, 
                    'account'     => trim($json->user->username),
                    'data'        => $account_ID_Token,
                    'sendername'  => $senderemail,
                  ],true),
                'service' => c::get('plugin.instagram-api.service', c::get('email.service', 'instagramapi-html')),
                ]);
              try{
                if (!$emailKirby || !$emailKirby->send()) {
                  throw new Error('The email to '.$email.' could not be sent.');
                }
              }
              catch (Error $e) {
                $message = $e->getMessage().' ';
                return go(site()->homepage()->url() . '#error=email-failed');
              }

              return go(site()->homepage()->url() . '#instagram=authorized');
            }
            catch(Exception $ex) {
              $error = urlencode($ex->getMessage());
            }
          } else {
            $error = 'invalid-redirect';
          }
        }
        
        return go(site()->homepage()->url() . '#error='.$error);
    }
  )
);

$kirby->set('route', 
  array(
      'pattern' => 'kirby-instagram-api/email/(:any)/(:any)/(:any)',
      'action' => function($username, $secret, $ajax) {
          $success = true;
          $message = c::get('plugin.instagram-api.field.success', ':)');

          // SECRET
          if($secret != sha1(kirby()->roots()->index().date('YmdH').$username)) {
            $success = false;
            $message = 'Timeout – try again. ';
          } 

          // js only PARAMs
          if($ajax != 'ajax') {
            $success = false;
            $message = 'Panel required. ';
          }

          // real ajax only
          if(!r::ajax()) {
            $success = false;
            //$message = 'Ajax Only.'; // no help for hackers
          }

          // user and email
          $user = site()->user($username);
          if(!$user || !v::email($user->email())) {
            $success = false;
            $message = 'User or User-Email invalid. ';
          }

          // sender
          $sender = site()->user();
          $sendername = $sender->firstname() .' '. $sender->lastname();
          if(strlen(trim($sendername)) == 0) $sendername = $sender->username();
          $senderemail = c::get('plugin.instagram-api.email.from', c::get('email.from', $sender->email()));
          if(!$sender || !v::email($senderemail)) {
            $success = false;
            $message = 'Sender or Sender-Email invalid. ';
          }

          // instagram api
          $clientid = c::get('plugin.instagram-api.client-id', '');
          $clientsecret = c::get('plugin.instagram-api.client-secret', '');
          if(strlen($clientid) != 32 || strlen($clientsecret) != 32) {
            $success = false;
            $message = 'Instagram API settings invalid. ';
          }

          // build redirect link
          if($success) {
            // https://www.instagram.com/developer/authentication/
            // STEP 1: https://api.instagram.com/oauth/authorize/?client_id=CLIENT-ID&redirect_uri=REDIRECT-URI&response_type=code

            $redirect = implode([
              site()->url(),
              '/kirby-instagram-api/redirect',
              '?u=' . $username,
            ]);
            $link = implode([
              'https://api.instagram.com/oauth/authorize/',
              '?client_id=' . $clientid,
              '&response_type=code',
              '&redirect_uri=' . $redirect, // last!
              ]);

            // send email to user
            $emailKirby = email([
                'to'      => $user->email(),
                'from'    => $senderemail,
                'subject' => c::get('plugin.instagram-api.email-request.subject', c::get('email.subject', 'Kirby CMS InstagramAPI Plugin: Authorization Email')),
                'body'    => snippet(c::get('plugin.instagram-api.email-request.body-snippet', 'instagramapi-email-request-body'),[
                    'user'        => $user, 
                    //'account'     => $account,
                    'link'        => $link,
                    'sendername'  => $sendername,
                  ],true),
                'service' => c::get('plugin.instagram-api.service', c::get('email.service', 'instagramapi-html')),
                ]);
            try{
              if (!$emailKirby || !$emailKirby->send()) {
                throw new Error('The email to '.$email.' could not be sent.');
              }
            }
            catch (Error $e) {
              $message = $e->getMessage().' ';
              $success = false;
            }

          }

          $json = [
            'message' => (!r::ajax()?'@ ':'') . trim($message),
            'code' => $success ? 200 : 400,
          ];

          $code = intval($json['code']);
          return response::json($json, $code);
      }
  )
);

/****************************************
  SITE METHOD
 ***************************************/

$kirby->set('site::method', 'instagramapi', 
  function( 
    $site, 
    $userOrName,
    $endpoint,
    $snippet = '',
    $params = []
    ) {

    return instagramapi($userOrName, $endpoint, $snippet, $params);
});

$kirby->set('site::method', 'instagramapiCacheImageToThumbs', 
  function( 
    $site, 
    $imgurl
    ) {

    $imgurlHash = md5($imgurl).'.'.f::extension($imgurl);
    $imgCachePath = kirby()->roots()->thumbs() . DS .$imgurlHash;
    $imgCacheURL = kirby()->urls()->thumbs() . '/' . $imgurlHash;
    $cachedImage = null;

    if(!f::exists($imgCachePath)) {
      $imageData = @file_get_contents($imgurl);
      if($imageData !== false && f::write($imgCachePath, $imageData)) {
        $cachedImage = new Media($imgCachePath, $imgCacheURL);
      }
    } else {
      $cachedImage = new Media($imgCachePath, $imgCacheURL);
    }

    return $cachedImage;
});

/****************************************
  PAGE METHOD
 ***************************************/

$kirby->set('page::method', 'instagramapi', 
  function( 
    $page, 
    $userOrName,
    $endpoint,
    $snippet = '',
    $params = []
    ) {

    return instagramapi($userOrName, $endpoint, $snippet, $params);
});

/****************************************
  KIRBY TAG
 ***************************************/

$kirby->set('tag', 'instagramapi', array(
  'attr' => array(
    'snippet',
    'endpoint',
  ),
  'html' => function($tag) {

    $userOrName = (string)$tag->attr('instagramapi');
    $endpoint = c::get('plugin.instagram-api.tag.endpoint', (string)$tag->attr('endpoint'));
    $snippet = c::get('plugin.instagram-api.tag.snippet', (string)$tag->attr('snippet'));
    $params = array();
    
    return instagramapi($userOrName, $endpoint, $snippet, $params);
  }
));

/****************************************
  WIDGET
 ***************************************/

if(str::length(c::get('plugin.instagram-api.license', '')) != 40) {
  // Hi there, play fair and buy a license. Thanks!
  $kirby->set('widget', 'instagramapi', __DIR__ . '/widgets/instagramapi');
}
