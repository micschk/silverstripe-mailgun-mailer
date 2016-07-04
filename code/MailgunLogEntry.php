<?php

class MailgunLogEntry extends DataObject
{

    private static $db = array(
        'MessageID' => 'Varchar(255)', // eg 20160628073205.28950.83173.152D93E2@send.goflex.nl
        'MessageAccepted' => 'SS_Datetime',
        'MessageRejected' => 'SS_Datetime',
        'MessageDelivered' => 'SS_Datetime',
        'MessageBounced' => 'SS_Datetime',
        'MessageOpened' => 'SS_Datetime',
        'LatestEvent' => 'Decimal(20,4)', // timestamp (float, total 20 positions, of which 4 right of comma)
        'EventsJSON' => 'Text' // JSON encoded array: array( 'timestamp-as-key' => event-object, repeat )
    );

    private static $indexes = array(
        'MessageID' => true,
        'LatestEvent' => true,
    );

    /**
     * Get or create a MailgunLogEntry by messageID
     * @param $messageID
     * @return DataObject
     */
    public static function GetOrCreate($messageID){
        if($existing = self::get()->filter('MessageID', $messageID)->first()) return $existing;
        $new = self::create();
        $new->MessageID = $messageID;
        return $new;
    }

    /**
     * Add an event log to a new or existing message log
     * @param $event
     */
    public static function LogEvent($event){
        if(!is_object($event)) { user_error('MailgunLog::AddEntry() expects $event to be object', E_USER_ERROR); }
        if(!$event->timestamp || !$event->event || !$event->recipient) {
            return user_error('MailgunLog::AddEvent(): $event missing required fields (timestamp, event, recipient), skipping', E_USER_WARNING);
        }
        if(! isset($event->message->headers->{'message-id'})){
            return user_error('$event missing message-id ($event->message->headers->{\'message-id\'}), skipping', E_USER_WARNING); }

        $messageLog = self::GetOrCreate($event->message->headers->{'message-id'});
        $messageEvents = $messageLog->getEvents(); // decode json
        // create key, replace . to make key a valid object property for json_encode/decode (js only has sequential arrays, or objects, no named arrays
        $eventKey = str_replace('.','_',$event->timestamp);

        // check if event hasnt already been logged
        if(isset($messageEvents[$eventKey])){ return; }

        // else, add event
        $messageEvents[$eventKey] = $event;
        krsort($messageEvents); // reverse-chronological order (eg lates first)
        $messageLog->EventsJSON = json_encode($messageEvents);

        // set timestamp if more recent than existing
        if($event->timestamp > $messageLog->LatestEvent) {
            $messageLog->LatestEvent = $event->timestamp;
        }
        // update statusflags
        $messageLog->updateStatusflags();

        $messageLog->write();
    }

    /**
     * Set status flags based on events log
     */
    public function updateStatusflags(){
        $this->MessageAccepted = null;
        $this->MessageRejected = null;
        $this->MessageDelivered = null;
        $this->MessageBounced = null;
        $this->MessageOpened = null;
        foreach($this->getEvents() as $key => $event){
            if($event->event == 'accepted') { $this->MessageAccepted = $event->timestamp; }
            if($event->event == 'rejected') { $this->MessageRejected = $event->timestamp; }
            if($event->event == 'delivered') { $this->MessageDelivered = $event->timestamp; }
            if($event->event == 'bounced') { $this->MessageBounced = $event->timestamp; }
            if($event->event == 'opened') { $this->MessageOpened = $event->timestamp; }
        }
    }

    /**
     * Get the json_decoded logentries as an array of objects
     * @return array
     */
    public function getEvents(){
        if(!$this->EventsJSON) return array();
        return (array) json_decode($this->EventsJSON);
    }

    /**
     * Return overview of events for message
     * @return string
     */
    public function SummaryTimeline(){
        $timeline = array($this->MessageID);
        foreach($this->getEvents as $event){
            $timeline[] = date("Y-m-d H:i:s", $event->timestamp) . "	{$event->event}: {$event->recipient}";
        }
        return implode("\n", $timeline);
    }

//    public function forTemplate(){
//        return nl2br($this->SummaryTimeline());
//    }

}