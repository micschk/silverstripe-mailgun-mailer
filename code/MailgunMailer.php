<?php

//use Mailgun\MailgunClient;
//use Mailgun\Models\MailgunAttachment;
//use Mailgun\Models\MailgunException;

/**
 * A {@link Mailer} subclass to handle sending emails through the Mailgun
 * webservice API rather than send_mail(). Uses the official Mailgun PHP library.
 */
class MailgunMailer extends Mailer
{

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
     * @config
     * @var string
     */
    private static $track_opens = false;

    /**
     * @config
     * @var array
     */
    private static $track_clicks = false;

    public static function getApiClient()
    {
        $http_client = new \Http\Adapter\Guzzle6\Client();
        $mg = new \Mailgun\Mailgun(self::getApiKey(), $http_client);
        return $mg;
    }

    public static function getApiDomain()
    {
        $apiDomain = self::config()->get('api_domain');
        if (!$apiDomain) user_error('A Mailgun API DOMAIN is required', E_USER_ERROR);
        return $apiDomain;
    }

    public static function getApiKey()
    {
        $apiKey = self::config()->get('api_key');
        if (!$apiKey) user_error('A Mailgun API key is required', E_USER_ERROR);
        return $apiKey;
    }

    /**
     * Send a plain-text email.
     *
     * @return bool
     */
    public function sendPlain($to, $from, $subject, $plainContent, $attachedFiles = false, $customheaders = false)
    {
        $result = $this->sendMailgunEmail($to, $from, $subject, false, $attachedFiles, $customheaders, $plainContent);
        if ($result === false) {
            // add our own message-id to headers and get reference
            $messageID = $this->createMessageId($to, $customheaders);
            // Fall back to regular Mailer
            $fallbackMailer = new Mailer();
            $result = $fallbackMailer->sendPlain($to, $from, $subject, $plainContent, $attachedFiles, $customheaders);

            return $this->emulateMailgunFeedback($result, $messageID);
        }
        return $result;
    }

    /**
     * Send an email as both HTML and plaintext
     *
     * @return array
     */
    public function sendHTML($to, $from, $subject, $htmlContent, $attachedFiles = false, $customheaders = false, $plainContent = false)
    {
        $result = $this->sendMailgunEmail($to, $from, $subject, $htmlContent, $attachedFiles, $customheaders, $plainContent);
        if ($result === false) {
            // add our own message-id to headers and get reference
            $messageID = $this->createMessageId($to, $customheaders);
            // Fall back to regular Mailer
            $fallbackMailer = new Mailer();
            $result = $fallbackMailer->sendHTML($to, $from, $subject, $htmlContent, $attachedFiles, $customheaders, $plainContent);

            return $this->emulateMailgunFeedback($result, $messageID);
        }
        return $result;
    }

    /**
     * Add a generated message ID to the customheaders array
     *
     * @param $customheaders
     * @param null $to
     * @return message-ID
     */
    private function createMessageId($to = null, & $existingHeadersToAddTo)
    {
        // generate & add a message-id
        if(!$to) $to = rand();
        $this->config()->get('api_domain') ? $domain = $this->config()->get('api_domain') : $domain = $_SERVER['SERVER_NAME'];
        $msg_id = sprintf("<%s.%s@%s>", time(), md5($to), $domain);

        // add/override id in existing headers (if any)
        if(is_array($existingHeadersToAddTo) ) {
            $customheaders['Message-ID'] = $msg_id;
        }

        return $msg_id;
    }

    /**
     * Return a Mailgun-compatible result object for fallbackMailer results (php mail()'s boolean result)
     * @TODO: not sure about returning a http_code/_body, as we're in fact making these up...
     *
     * @param bool $result
     * @return object
     */
    private function emulateMailgunFeedback($success = false, $message_id = '<none>')
    {
        // Default silverstripe mailer returns message object instead of 'true' if sending successful, false if unsuccesful
        if($success===false){
            $ret = array(
                'http_response_body' => array(
                    'id' => '<none>',
                    'message' => 'Error: Mail not sent (tried Mailgun & Mailer, details may have been logged in error log)',
                ),
                'http_response_code' => 500, // equivalent of 'Error'
            );
            return (object) $ret;
        }
        // else
        $ret = array(
            'http_response_body' => array(
                // $message-id = time() .'-' . md5($sender . $recipient) . '@' $_SERVER['SERVER_NAME'];
                'id' => $message_id,
                'message' => 'OK: Sent via fallback (Mailer) - Mailgun unsuccessful (details may have been logged in error log)',
            ),
            'http_response_code' => 200, // equivalent of 'OK'
        );
        return (object) $ret;
    }

    /**
     * Send email through Mailgun's REST API
     *
     * @return bool (true = sent successfully)
     */
    private function sendMailgunEmail($to, $from, $subject, $htmlContent = NULL, $attachedFiles = NULL, $customHeaders = NULL, $plainContent = NULL)
    {
        $cc = NULL;
        $bcc = NULL;
        $replyTo = NULL;

        if (!($htmlContent || $plainContent)) user_error("Can't send email with no content", E_USER_ERROR);

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
        } else {
            $customHeaders = NULL;
        }

        // Ensure from address is valid
//		if (!in_array($from, $api_domain)) {
//			// Fallback to first valid signature
//			if (!$replyTo) $replyTo = $from;
//			$from = $sender_domains[0];
//		}

        // Send the email
        try {
            $mg = self::getApiClient();

            // https://documentation.mailgun.com/api-sending.html#sending
            // https://github.com/mailgun/mailgun-php/tree/master/src/Mailgun/Messages
            $mb = $mg->MessageBuilder();
            $mb->setFromAddress($from);
            //		$mb->addToRecipient("john.doe@example.com", array("first" => "John", "last" => "Doe"));
            $mb->addToRecipient($to);
            $mb->setSubject($subject);

            if ($cc) $mb->addCcRecipient($cc);
            if ($bcc) $mb->addBccRecipient($bcc);
            if ($replyTo) $mb->setReplyToAddress($replyTo);
            if ($plainContent) $mb->setTextBody($plainContent);
            if ($htmlContent) $mb->setHtmlBody($htmlContent);
            if ($customHeaders && is_array($customHeaders)) {
                foreach ($customHeaders as $headerName => $headerValue) {
                    $mb->addCustomHeader($headerName, $headerValue);
                }
            }
            if ($attachedFiles && is_array($attachedFiles)) {
                foreach ($attachedFiles as $f) {
                    //$attachments[] = ::fromRawData($f['contents'], $f['filename'], $f['mimetype']);
                    // mailgun PHP SDK can only work with filepaths, so write to temporary file
                    //$tmp_file_path = tempnam(sys_get_temp_dir(), 'att_');
                    $tmp_file_path = stream_get_meta_data(tmpfile())['uri'];
                    file_put_contents($tmp_file_path, $f['contents']);
                    $mb->addAttachment($tmp_file_path, $f['filename']);
                }
            }
            //		$mb->addAttachment("@/tron.jpg");
            //		$mb->addCampaignId("My-Awesome-Campaign");
            //		$mb->setDeliveryTime("tomorrow 8:00AM", "PST");
            $mb->setOpenTracking($this->config()->get('track_opens'));
            $mb->setClickTracking($this->config()->get('track_clicks'));
            
            # send the message.
            $result = $mg->post(self::getApiDomain()."/messages", $mb->getMessage(), $mb->getFiles());

            // result = stdClass Object (
            // 		[http_response_body] => stdClass Object (
            // 			[id] => <20160628151907.31410.81436.6F3FCED6@send.goflex.nl>
            // 			[message] => Queued. Thank you.
            // 		)
            //  	[http_response_code] => 200 )

            return $result;

        } catch (\Exception $e) {
            // A general exception is thown if the API was unreachable or times out.
            user_error('Mailgun API exception: ' . $e->getMessage(), E_USER_WARNING);
            return false;
        }
    }
}
