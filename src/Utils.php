<?php

class DiscordUtils {
	/**
	 * Checks if criteria is met for this action to be cancelled
	 */
	public static function isDisabled ( $hook, $ns, $user ) {
		global $wgDiscordDisabledHooks, $wgDiscordDisabledNS, $wgDiscordDisabledUsers;

		if ( is_array( $wgDiscordDisabledHooks ) ) {
			if ( in_array( strtolower( $hook ), array_map( 'strtolower', $wgDiscordDisabledHooks ) ) ) {
				// Hook is disabled, return true
				return true;
			}
		} else {
			wfDebugLog( 'discord', 'The value of $wgDiscordDisabledHooks is not valid and therefore all hooks are enabled.' );
		}
		if ( is_array( $wgDiscordDisabledNS ) ) {
			if ( !is_null( $ns ) ) {
				$ns = (int)$ns;
				if ( in_array( $ns, $wgDiscordDisabledNS ) ) {
					// Namespace is disabled, return true
					return true;
				}
			}
		} else {
			wfDebugLog( 'discord', 'The value of $wgDiscordDisabledNS is not valid and therefore all namespaces are enabled.' );
		}
		if ( is_array( $wgDiscordDisabledUsers ) ) {
			if ( !is_null( $user ) ) {
				if ( $user instanceof User ) {
					if ( in_array( $user->getName(), $wgDiscordDisabledUsers ) ) {
						// User shouldn't trigger a message, return true
						return true;
					}
				}
			}
		} else {
			wfDebugLog( 'discord', 'The value of $wgDiscordDisabledUsers is not valid and therefore all users can trigger messages.' );
		}

		return false;
	}

	/**
	 * Handles sending a webhook to Discord using cURL
	 */
	public static function handleDiscord ($hookName, $msg) {
		global $wgDiscordWebhookURL, $wgDiscordEmojis, $wgDiscordUseEmojis, $wgDiscordPrependTimestamp, $wgDiscordUseFileGetContents;

		if ( !$wgDiscordWebhookURL ) {
			// There's nothing in here, so we won't do anything
			return false;
		}

		$urls = [];

		if ( is_array( $wgDiscordWebhookURL ) ) {
			$urls = array_merge($urls, $wgDiscordWebhookURL);
		} else if ( is_string($wgDiscordWebhookURL) ) {
			$urls[] = $wgDiscordWebhookURL;
		} else {
			wfDebugLog( 'discord', 'The value of $wgDiscordWebhookURL is not valid and therefore no webhooks could be sent.' );
			return false;
		}

		// Strip whitespace to just one space
		$stripped = preg_replace('/\s+/', ' ', $msg);

		if ( $wgDiscordPrependTimestamp ) {
			// Add timestamp
			$dateString = gmdate( wfMessage( 'discord-timestampformat' )->text() );
			$stripped = $dateString . ' ' . $stripped;
		}

		if ( $wgDiscordUseEmojis ) {
			// Add emoji
			$emoji = $wgDiscordEmojis[$hookName];
			$stripped = $emoji . ' ' . $stripped;
		}

		DeferredUpdates::addCallableUpdate( function() use ( $stripped, $urls, $wgDiscordUseFileGetContents ) {
			$user_agent = 'mw-discord/1.0 (github.com/jaydenkieran)';
			$json_data = [ 'content' => "$stripped" ];
			$json = json_encode($json_data);	

			if ( $wgDiscordUseFileGetContents ) {
				// They want to use file_get_contents
				foreach ($urls as &$value) {
					$contextOpts = [
						'http' => [
							'header' => 'Content-Type: application/x-www-form-urlencoded',
							'method' => 'POST', // Send as a POST request
							'user_agent' => $user_agent, // Add a unique user agent
							'content' => $json, // Send the JSON in the POST request
							'ignore_errors' => true // If the call fails, let's not do anything with it
						]
					];

					$context = stream_context_create( $contextOpts );
					$result = file_get_contents( $value, false, $context );
				}
			} else {
				// By default, we use cURL	
				// Set up cURL multi handlers
				$c_handlers = [];
				$result = [];
				$mh = curl_multi_init();
	
				foreach ($urls as &$value) {
					$c_handlers[$value] = curl_init( $value );
					curl_setopt( $c_handlers[$value], CURLOPT_POST, 1 ); // Send as a POST request
					curl_setopt( $c_handlers[$value], CURLOPT_POSTFIELDS, $json ); // Send the JSON in the POST request
					curl_setopt( $c_handlers[$value], CURLOPT_FOLLOWLOCATION, 1 );
					curl_setopt( $c_handlers[$value], CURLOPT_HEADER, 0 );
					curl_setopt( $c_handlers[$value], CURLOPT_RETURNTRANSFER, 1 );
					curl_setopt( $c_handlers[$value], CURLOPT_CONNECTTIMEOUT, 10 ); // Add a timeout for connecting to the site
					curl_setopt( $c_handlers[$value], CURLOPT_TIMEOUT, 10 ); // Do not allow cURL to run for a long time
					curl_setopt( $c_handlers[$value], CURLOPT_USERAGENT, $user_agent ); // Add a unique user agent
					curl_setopt( $c_handlers[$value], CURLOPT_HTTPHEADER, array(
						'Content-Type: application/json'
					));
					curl_multi_add_handle( $mh, $c_handlers[$value] );
				}
	
				$running = null;
				do {
					curl_multi_exec($mh, $running);
				} while ($running);
	
				// Remove all handlers and then close the multi handler
				foreach($c_handlers as $k => $ch) {
					$result[$k] = curl_multi_getcontent($ch);
					wfDebugLog( 'discord', 'Result of cURL was: ' . $result[$k] );
					curl_multi_remove_handle($mh, $ch);
				}
	
				curl_multi_close($mh);
			}
		} );

		return true;
	}

	/**
	 * Creates a formatted markdown link based on text and given URL
	 */
	public static function createMarkdownLink ($text, $url)  {
		global $wgDiscordSuppressPreviews;

		return "[" . $text . "]" . '(' . ($wgDiscordSuppressPreviews ? '<' : '') . self::encodeURL($url) . ($wgDiscordSuppressPreviews ? '>' : '') . ')';
	}

	/**
	 * Creates links for a specific MediaWiki User object
	 */
	public static function createUserLinks ($user) {
		global $wgDiscordMaxCharsUsernames;

		if ( $user instanceof User ) {
			$isAnon = $user->isAnon();
			$contribs = Title::newFromText("Special:Contributions/" . $user);

			if ($wgDiscordMaxCharsUsernames) {
				$user_abbr = strval($user);
				if (strlen($user_abbr) > $wgDiscordMaxCharsUsernames) {
					$user_abbr = substr($user_abbr, 0, $wgDiscordMaxCharsUsernames);
					$user_abbr = $user_abbr.'...';
				}
			}

			$userPage = DiscordUtils::createMarkdownLink(	$user_abbr, ( $isAnon ? $contribs : $user->getUserPage() )->getFullUrl([ '', '', $proto = PROTO_HTTP] ) );
			$userTalk = DiscordUtils::createMarkdownLink( wfMessage( 'discord-talk' )->text(), $user->getTalkPage()->getFullUrl([ '', '', $proto = PROTO_HTTP] ) );
			$userContribs = DiscordUtils::createMarkdownLink( wfMessage( 'discord-contribs' )->text(), $contribs->getFullURL([ '', '', $proto = PROTO_HTTP ]) );
			$text = wfMessage( 'discord-userlinks', $userPage, $userTalk, $userContribs )->text();	
		} else {
			// If it's a string, which can be likely (for example when range blocking a user)
			// We need to handle this differently.
			$text = wfMessage( 'discord-userlinks', $user, 'n/a', 'n/a' )->text();
		}
		return $text;
	}

	/**
	 * Creates formatted text for a specific Revision object
	 */
	public static function createRevisionText ($revisionRecord) {
//		$diff = DiscordUtils::createMarkdownLink( wfMessage( 'discord-diff' )->text(), $revisionRecord->getPageAsLinkTarget()->getFullUrl(["diff=prev", ["oldid" => $revision->getID()], $proto = PROTO_HTTP]) );
		$diff = DiscordUtils::createMarkdownLink( wfMessage( 'discord-diff' )->text(), $revisionRecord->getPage()->getFullUrl(["diff=prev",["oldid" => $revisionRecord->getID()]]));
		$minor = '';
		$size = '';
		if ( $revisionRecord->isMinor() ) {
			$minor .= wfMessage( 'discord-minor' )->text();
		}
		/*
		$previous = $revisionRecord->getPrevious();
		if ( $previous ) {
			$size .= wfMessage( 'discord-size', sprintf( "%+d", $revisionRecord->getSize() - $previous->getSize() ) )->text();
		} else if ( $revisionRecord->getParentId() ) {
			// Try and get the parent revisionRecord based on the ID, if we can
			$previous = Revision::newFromId( $revisionRecord->getParentId() );
			if ($previous) {
				$size .= wfMessage( 'discord-size', sprintf( "%+d", $revisionRecord->getSize() - $previous->getSize() ) )->text();
			}
		}
		 */
		if ( $size == '' ) {
			$size .= wfMessage( 'discord-size', sprintf( "%d", $revisionRecord->getSize() ) )->text();
		}
		$text = wfMessage( 'discord-revisionlinks', $diff, $minor, $size )->text();
		return $text;
	}
	
	/**
	 * Strip bad characters from a URL
	 */
	public static function encodeURL($url) {
		$url = str_replace(" ", "%20", $url);
		$url = str_replace("(", "%28", $url);
		$url = str_replace(")", "%29", $url);
		$url = str_replace("%3D", "=", $url);
		$url = str_replace("1%5B", "", $url);
		$url = str_replace("%5D", "", $url);
		$url = str_replace("&0=", "&", $url);
		return $url;
	}
	
	/**
	 * Formats bytes to a string representing B, KB, MB, GB, TB
	 */
	public static function formatBytes($bytes, $precision = 2) { 
    $units = array('B', 'KB', 'MB', 'GB', 'TB'); 

    $bytes = max($bytes, 0); 
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
    $pow = min($pow, count($units) - 1); 

    $bytes /= (1 << (10 * $pow)); 

    return round($bytes, $precision) . ' ' . $units[$pow]; 
	}

	/**
	 * Truncate text to maximum allowed characters
	 */
	public static function truncateText($text) {
		global $wgDiscordMaxChars;
		if ($wgDiscordMaxChars) {
			if (strlen($text) > $wgDiscordMaxChars) {
				$text = substr($text, 0, $wgDiscordMaxChars);
				$text = $text.'...';
			}
		}
		return $text;
	}

	/**
	 * Sanitise text input, including removing the potential for abuse
	 * of Discord's @everyone and @here pings
	 */
	public static function sanitiseText($text) {
		$text = preg_replace('/(`|@)/', '', $text);
		return $text;
	}
}

?>
