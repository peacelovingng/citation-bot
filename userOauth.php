<?php
 // To use the oauthclient library, run:
 // composer require mediawiki/oauthclient
 use MediaWiki\OAuthClient\Consumer;
 use MediaWiki\OAuthClient\Token;
 use MediaWiki\OAuthClient\Request;
 use MediaWiki\OAuthClient\SignatureMethod\HmacSha1;
 use MediaWiki\OAuthClient\ClientConfig;
 use MediaWiki\OAuthClient\Client;

 final class userOauth {

   private $editToken;
   private $client;
   private $user;
  
   function __construct() {
      $conf = new ClientConfig('https://meta.wikimedia.org/w/index.php?title=Special:OAuth');
      $conf->setConsumer(new Consumer(getenv('PHP_OAUTH_CONSUMER_TOKEN_USER'), getenv('PHP_OAUTH_CONSUMER_SECRET_USER')));  // Is this correct, or do we need a new token?
      $this->client = new Client($conf);
      if (isset( $_GET['oauth_verifier'] ) ) {
         $this->get_token();
      } else {
         $this->authorize_token();
      }
   }

   public function get_edit_token() {
      return $this->editToken;
   }
	 
   public function get_user() {
      return $this->user;
   }

   private function get_token() {
     // Get the Request Token's details from the session and create a new Token object.
     $requestToken = new Token( $_SESSION['request_key'], $_SESSION['request_secret'] );
     // Send an HTTP request to the wiki to retrieve an Access Token.
     $accessToken = $this->client->complete( $requestToken,  $_GET['oauth_verifier'] );
     // At this point, the user is authenticated, and the access token can be used
     $_SESSION['access_key'] = $accessToken->key;
     $_SESSION['access_secret'] = $accessToken->secret;
     //   get the authenticated user's identity.
     $ident = $this->client->identify( $accessToken );
     $this->user = $ident->username;
     // get the authenticated user's edit token.
     $this->editToken = json_decode( $client->makeOAuthCall(
	$accessToken,
	'https://meta.wikimedia.org/w/api.php?action=query&meta=tokens&format=json'
     ) )->query->tokens->csrftoken;
     unset( $_SESSION['request_key'], $_SESSION['request_secret'] ); // No longer needed
   }
  
   private function authorize_token() {
     // Send an HTTP request to the wiki to get the authorization URL and a Request Token.
     // These are returned together as two elements in an array (with keys 0 and 1).
     list( $authUrl, $token ) = $this->client->initiate();
     // Store the Request Token in the session. We will retrieve it from there when the user is sent back from the wiki
     $_SESSION['request_key'] = $token->key;
     $_SESSION['request_secret'] = $token->secret;
     // Redirect the user to the authorization URL.
     @header("Location: https://meta.wikimedia.org/w/index.php?title=Special:OAuth"); // Automatic, but assumes that no HTML has been sent
     echo "<br />Go to this URL to <a href='https://meta.wikimedia.org/w/index.php?title=Special:OAuth'>authorize citation bot</a>"; // Manual too
     exit();
   }
 }
