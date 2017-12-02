<?php
require_once("credentials/wiki.php");

class WikipediaBot {
  
  private $header;
  
  function __construct() {
    quiet_echo("\n Establishing connection to Wikipedia servers via OAuth... ");
    $oauth = new OAuth(OAUTH_CONSUMER_TOKEN, OAUTH_CONSUMER_SECRET);
    $oauth->setToken(OAUTH_ACCESS_TOKEN, OAUTH_ACCESS_SECRET);
    
    $this->header = "Accept-language: en\r\n" .
                    "Cookie: foo=bar\r\n" .
                    "User-agent: Citation-bot\r\n" .
                    'Authorization: ' . 
                         $oauth->getRequestHeader('GET', API_ROOT, array(
                           'action'=>'query',
                           'meta'=>'tokens',
                           'type'=>'login')
                         ) . "\r\n";
    
  }
  
  private function post($url, $content) {
    $post_opts = array(
      'http' => array(
        'method' => "POST",
        'query' => http_build_query($content),
        'header' => $header
      )
    );
    return @file_get_contents($url, FALSE, stream_context_create($opts));
  }
  
  private function get($url) {
    $opts = array(
      'http' => array(
        'method' => "GET",
        'header' => $header
      )
    );
    return @file_get_contents($url, FALSE, stream_context_create($opts));
  }
    
  public function write_page($page, $text, $editSummary, $lastRevId = NULL) {
    $response = json_decode($this->get(API_ROOT . 'action=query&prop=info|revisions&titles=' .
                    urlencode($page)));
    if (isset($response->error)) {
      trigger_error((string) $response->error->info, E_USER_ERROR);
      return FALSE;
    }
    if (isset($response->warnings)) {
      trigger_error((string) $response->warnings->info->{'*'}, E_USER_WARNING);
    }
    if (!isset($response->batchcomplete)) {
      trigger_error("Write request triggered no response from server", E_USER_WARNING);
      return FALSE;
    }
    
    $myPage = reset($response->query->pages); // reset gives first element in list
    if (!isset($myPage->lastrevid)) {
      trigger_error(" ! Page seems not to exist. Aborting.", E_USER_WARNING);
      return FALSE;
    }
    if (!is_null($lastRevId) && $myPage->lastrevid != $lastRevId) {
      echo "\n ! Possible edit conflict detected. Aborting.";
      return FALSE;
    }
    if (stripos($text, "CITATION_BOT_PLACEHOLDER") != FALSE)  {
      trigger_error("\n ! Placeholder left escaped in text. Aborting.", E_USER_WARNING);
      return FALSE;
    }
    
    // No obvious errors; looks like we're good to go ahead and edit
    $submit_vars = array(
        "action" => "edit",
        "title" => $page,
        "text" => $text,
        "summary" => $editSummary,
        "minor" => "1",
        "bot" => "1",
        "basetimestamp" => $myPage->touched,
        #"md5"       => hash('md5', $data), // removed by MS because I can't figure out how to make the hash of the UTF-8 encoded string that I send match that generated by the server.
        "watchlist" => "nochange",
        "format" => "json",
    );
    $result = json_decode($this->submit(API_ROOT, $submit_vars));
    if (isset($result->edit) && $result->edit->result == "Success") {
      // Need to check for this string whereever our behaviour is dependant on the success or failure of the write operation
      if (HTML_OUTPUT) {
        echo "\n <span style='color: #e21'>Written to <a href='" 
        . WIKI_ROOT . "title=" . urlencode($myPage->title) . "'>" 
        . htmlspecialchars($myPage->title) . '</a></span>';
      }
      else echo "\n Written to " . htmlspecialchars($myPage->title) . '.  ';
      return TRUE;
    } elseif (isset($result->edit->result)) {
      echo htmlspecialchars($result->edit->result);
      return TRUE;
    } elseif ($result->error->code) {
      // Return error code
      echo "\n ! Write error: " . htmlspecialchars(strtoupper($result->error->code)) . ": " . str_replace(array("You ", " have "), array("This bot ", " has "), htmlspecialchars($result->error->info));
      return FALSE;
    } else {
      echo "\n ! Unhandled error.  Please copy this output and <a href=http://code.google.com/p/citation-bot/issues/list>report a bug.</a>";
      return FALSE;
    }
  }  
}

