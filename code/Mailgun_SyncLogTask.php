<?php

/*
bounce	Tracking Bounces
deliver	Tracking Deliveries
drop	Tracking Failures
spam	Tracking Spam Complaints
unsubscribe	Tracking Unsubscribes
click	Tracking Clicks
open	Tracking Opens
 */

class Mailgun_SyncLogTask extends BuildTask
{

    protected $title = 'Sync Mailgun events log';

    protected $description = 'Polls the Mailgun API for events and saves them to the database';

    protected $enabled = true;

    // This used to be a Controller action...
//    private static $allowed_actions = array(
//        'synclog',
//    );

    // But now it's a buildtask
    public function run($request) {
        self::poll();
    }

    /**
     * @param string $queryURL
     * @param array $additionalFilters Array of filters to add to API query
     * @return mixed
     */
    public static function poll($queryURL = null, $additionalFilters = null)
    {
        $mgClient = MailgunMailer::getApiClient();
        $mgDomain = MailgunMailer::getApiDomain();

        if(!$queryURL) {
            // build query
            $query = array();
            $query['limit'] = 25; // page per 25 items

            // only get most recent log item-timestamp (substract 1 min for some overlap)
            $latestSync = MailgunLogEntry::get()->sort('LatestEvent', 'DESC')->first();
            if ($latestSync) {
                $query['begin'] = $latestSync->LatestEvent - 60;
                $query['ascending'] = 'yes';
                print("<b>Syncing from:</b> " . date("Y-m-d H:i:s", $latestSync->LatestEvent - 60) . '<br />');
            }
            // run query
            $queryURL = "$mgDomain/events";
            $results = $mgClient->get($queryURL, $query);
        } else {
            // make $queryURL relative to api/domain (returned URL includes domain, eg: https://api.mailgun.net/v2/send.goflex.nl/events/W3siYSI...)
            if(strpos($queryURL,"$mgDomain/events")) {
                $queryParts = explode("$mgDomain/events", $queryURL);
                $queryURL = "$mgDomain/events".$queryParts[1];
                print("<b>QUERY URL</b>: ".$queryURL.'<br />');
            }
            $results = $mgClient->get($queryURL);
        }

        // process results
        if($results->http_response_code !== 200){
            user_error('Non-200 result from Mailgun API ('.$queryURL.')',E_USER_WARNING);
        }
        foreach($results->http_response_body->items as $item){
            print("<b>Processing</b>: {$item->message->headers->{'message-id'}} {$item->event} ".date("Y-m-d H:i:s",$item->timestamp).'<br />');
            MailgunLogEntry::LogEvent($item);
        }

        // traverse to next pages until no results returned
        if(count($results->http_response_body->items) && isset($results->http_response_body->paging->next)
                //&& $results->http_response_body->paging->next !== $squeryURL
                ){
            print("<b>Items on this page (processed)</b>: ".count($results->http_response_body->items).'<br />');
            return self::poll($results->http_response_body->paging->next);
        } else {
            print "<br /><br /><b>DONE</b><br /><br />";
        }
    }

//    public function setHooks()
//    {
//        $mgClient = MailgunMailer::getApiClient();
//        $mgDomain = MailgunMailer::getApiDomain();
//
//        // Pipe ('|') separated list of hooks to set
//        $hooks_to_set = explode('|', $this->getRequest()->param('HooksToSet'));
//
//        foreach($hooks_to_set as $hookname)
//        {
//            $result = $mgClient->post("domains/$mgDomain/webhooks", array(
//                'id'  => $hookname,
//                'url' => Director::BaseURL().'/'.self::$url_segment.'/log/'.$hookname
//            ));
//        }
//    }
//
//    public function logHook()
//    {
//        // verify
//        if(!$this->validRequest()) return;
//
//        /*
//event	Event name (delivered).
//recipient	Intended recipient.
//domain	Domain that sent the original message.
//message-headers	String list of all MIME headers dumped to a JSON string (order of headers preserved).
//Message-Id	String id of the original message delivered to the recipient.
//custom variables	Your own custom JSON object included in the header of the original message (see Attaching Data to Messages).
//timestamp	Number of seconds passed since January 1, 1970 (see securing webhooks).
//token	Randomly generated string with length 50 (see securing webhooks).
//signature	String with hexadecimal digits generate by HMAC algorithm (see securing webhooks).
//         */
//
//        $event = $this->getRequest()->postVar('event');
//
//    }
//
//    private function validRequest()
//    {
//        //$apiKey, $token, $timestamp, $signature
//        $apiKey = MailgunMailer::getApiKey();
//        $token = $this->getRequest()->postVar();
//        $timestamp = $this->getRequest()->postVar();
//        $signature = $this->getRequest()->postVar();
//
//        // check all set
//        if(!$apiKey || !$token || !$timestamp || !$signature){
//            return false;
//        }
//
//        // check if the timestamp is fresh
//        if (time()-$timestamp>15) {
//            return false;
//        }
//
//        //returns true if signature is valid
//        return hash_hmac('sha256', $timestamp.$token, $apiKey) === $signature;
//    }


}