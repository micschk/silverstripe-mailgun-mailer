<?php

//use Mailgun\MailgunClient;
//use Mailgun\Models\MailgunAttachment;
//use Mailgun\Models\MailgunException;

/**
 * A {@link Mailer} subclass to handle sending emails through the Mailgun 
 * webservice API rather than send_mail(). Uses the official Mailgun PHP library.
 */

class MailgunMailer extends Mailer {
	
	/**
	 * Your Mailgun App API Key. Get one at https://mailgun.com/
	 *
	 * @config
	 * @var string
	 */
	private static $api_key = '';

	/**
	 * List of confirmed domains. Set them up at https://mailgun.com/
	 *
	 * @config
	 * @var array
	 */
	private static $api_domain = '';

	/**
	 * Send a plain-text email.
	 *
	 * @return bool
	 */
	public function sendPlain($to, $from, $subject, $plainContent, $attachedFiles = false, $customheaders = false) {
		$result = $this->sendMailgunEmail($to, $from, $subject, false, $attachedFiles, $customheaders, $plainContent);
		if ($result === false) {
			// Fall back to regular Mailer
			$fallbackMailer = new Mailer();
			$result = $fallbackMailer->sendPlain($to, $from, $subject, $plainContent, $attachedFiles, $customheaders);
		}
		return $result;
	}
	
	/**
	 * Send an email as both HTML and plaintext
	 * 
	 * @return bool
	 */
	public function sendHTML($to, $from, $subject, $htmlContent, $attachedFiles = false, $customheaders = false, $plainContent = false) {
		$result = $this->sendMailgunEmail($to, $from, $subject, $htmlContent, $attachedFiles, $customheaders, $plainContent);
		if ($result === false) {
			// Fall back to regular Mailer
			$fallbackMailer = new Mailer();
			$result = $fallbackMailer->sendHTML($to, $from, $subject, $htmlContent, $attachedFiles, $customheaders, $plainContent);
		}
		return $result;
	}
	
	/**
	 * Send email through Mailgun's REST API
	 *
	 * @return bool (true = sent successfully)
	 */
	private function sendMailgunEmail($to, $from, $subject, $htmlContent = NULL, $attachedFiles = NULL, $customHeaders = NULL, $plainContent = NULL) {
		
		$apiKey = $this->config()->get('api_key');
		$apiDomain = $this->config()->get('api_domain');

		$cc = NULL;
		$bcc = NULL;
		$replyTo = NULL;
		
		if(!$apiKey) user_error('A Mailgun API key is required to send email', E_USER_ERROR);
		if(!$apiDomain) user_error('A Mailgun API DOMAIN is required to send email', E_USER_ERROR);
		if(!($htmlContent||$plainContent)) user_error("Can't send email with no content", E_USER_ERROR);
		
		// Parse out problematic custom headers
		if (is_array($customHeaders)) {
			if (array_key_exists('Cc', $customHeaders)) {
				$cc = $customHeaders['Cc'];
				unset($customHeaders['Cc']);
			}
			if (array_key_exists('Bcc', $customHeaders)) {
				$bcc = $customHeaders['Bcc'];
				unset($customHeaders['Bcc']);
			}
			if (array_key_exists('Reply-To', $customHeaders)) {
				$replyTo = $customHeaders['Reply-To'];
				unset($customHeaders['Reply-To']);
			}
			if (empty($customHeaders)) $customHeaders = NULL;
		} else {$customHeaders = NULL;}
		
		// Ensure from address is valid
//		if (!in_array($from, $api_domain)) {
//			// Fallback to first valid signature
//			if (!$replyTo) $replyTo = $from;
//			$from = $sender_domains[0];
//		}
		
		// Set up attachments
		$attachments = array();
		if ($attachedFiles && is_array($attachedFiles)) {
			foreach ($attachedFiles as $f) {
				$attachments[] = MailgunAttachment::fromRawData($f['contents'], $f['filename'], $f['mimetype']);
			}
		}
		
		// Send the email
		try {
			$http_client = new \Http\Adapter\Guzzle6\Client();
			$mg = new \Mailgun\Mailgun($apiKey, $http_client);
			// https://documentation.mailgun.com/api-sending.html#sending
			// or use message builder; https://github.com/mailgun/mailgun-php/tree/master/src/Mailgun/Messages
			$mg->sendMessage($apiDomain, array(
				'from'    => $from,
				'to'      => $to,
				'cc'      => $cc,
				'bcc'	  => $bcc,
				'subject' => $subject,
				'html'	  => $htmlContent,
				'text'    => $htmlContent,
				'o:tag'   => null,
				'o:tracking' => 'yes or no',
				'h:Reply-To' => $replyTo,
//				TODO: $customHeaders & $attachments
			));

			return true;
		}
//		catch(MailgunException $ex) {
//			// If client is able to communicate with the API in a timely fashion,
//			// but the message data is invalid, or there's a server error,
//			// a MailgunException can be thrown.
//			user_error("Mailgun Exception: $ex->message (Error code: $ex->postmarkApiErrorCode)", E_USER_WARNING);
//			return false;
//		}
		catch(\Exception $generalException) {
			// A general exception is thown if the API
			// was unreachable or times out.
			user_error('Mailgun API error, unreachable or timed out', E_USER_WARNING);
			return false;
		}
	}
}