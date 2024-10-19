<?php

require('./vendor/autoload.php');
use Postmark\PostmarkClient;

date_default_timezone_set("Europe/London");

// replace with conf vars
$configs = array();
$configs['product'] = 'AGILE-24-04-03';
$configs['tariff'] = 'E-1R-AGILE-24-04-03-C';
$configs['apikey'] = getenv('apikey');
$configs['postmark'] = getenv('postmark');
$configs['low'] = getenv('low');
$configs['high'] = getenv('high');

// push
$configs['poToken'] = getenv('po_token');
$configs['poUser'] = getenv('po_user');

class octopusDay {

    public  $date;
    public  $cheapTimes = array();
    public  $expensiveTimes = array();
    public  $rawPrices;

    public function __construct($configs){
        // construct 

        // set date
        $this->date = new DateTime('tomorrow midnight');
        // $this->date = new DateTime('today midnight');
        $this->date->setTimezone(new DateTimeZone('UTC'));
        $this->rawPrices = FALSE; // until fetched

        // CURL
        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.octopus.energy/v1/products/'.$configs['product'].'/electricity-tariffs/'.$configs['tariff'].'/standard-unit-rates/?period_from='.$this->date->format('Y-m-d\TH:i:s\Z').'&period_to='.$this->date->modify('+1 day')->format('Y-m-d\TH:i:s\Z'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Authorization: '.$configs['apikey']
        ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $tempJson = json_decode($response);

        if ($tempJson->count == 46){
            $this->rawPrices = $tempJson;
        }

        return $this; 
    }
    
    public function fixTimeZone($dateTimeString){
        // format times from Zulu to local in case BST
        $thisDate = new DateTime($dateTimeString);
        $thisDate->setTimezone(new DateTimeZone('Europe/London'));
        return($thisDate->format('H:i')); 
    }

    public function findPricePeriods($t_low,$t_high){

        if ($this->rawPrices->count != 46){
            // failed
            return;
        }

        $prices_array = array_reverse($this->rawPrices->results);

        $startLow = $startHigh = $lowPrice = $highPrice = FALSE;

        foreach ($prices_array AS $time_segment){

            if ($time_segment->value_inc_vat > $t_high){
                if (!$startHigh){
                    $startHigh = $this->fixTimeZone($time_segment->valid_from);
                }
                if (!$highPrice){
                    $highPrice = $time_segment->value_inc_vat;
                }
                else if ($time_segment->value_inc_vat > $highPrice){
                    $highPrice = $time_segment->value_inc_vat;
                }
            }
            else {
                if ($startHigh !== FALSE){
                    // high closed
                    $highPeriod = array();
                    $highPeriod['from'] = $startHigh;
                    $highPeriod['to'] = $this->fixTimeZone($time_segment->valid_from);
                    $highPeriod['peak'] = $highPrice;
                    array_push($this->expensiveTimes,$highPeriod);
                    $startHigh = $highPrice = FALSE;
                }
            }

            if ($time_segment->value_inc_vat < $t_low ){
                if (!$startLow){
                    $startLow = $this->fixTimeZone($time_segment->valid_from);
                }
                if (!$lowPrice){
                    $lowPrice = $time_segment->value_inc_vat;
                }
                else if ($time_segment->value_inc_vat < $lowPrice){
                    $lowPrice = $time_segment->value_inc_vat;
                }
            }
            else {
                if ($startLow !== FALSE){
                    // low closed
                    $lowPeriod = array();
                    $lowPeriod['from'] = $startLow;
                    $lowPeriod['to'] = $this->fixTimeZone($time_segment->valid_from);
                    $lowPeriod['peak'] = $lowPrice;
                    array_push($this->cheapTimes,$lowPeriod);
                    $startLow = $lowPrice = FALSE;
                }
            }

            // store last time for use after loop
            $last_time = $this->fixTimeZone($time_segment->valid_to);
        }
        // handle 'day ends in low/high period'
        if ($startHigh !== FALSE){
            // ended day in high
            $highPeriod = array();
            $highPeriod['from'] = $startHigh;
            $highPeriod['to'] = $last_time;
            $highPeriod['peak'] = $highPrice;
            array_push($this->expensiveTimes,$highPeriod);
        }
        if ($startLow !== FALSE){
            // ended day in low
            $lowPeriod = array();
            $lowPeriod['from'] = $startLow;
            $lowPeriod['to'] = $last_time;
            $lowPeriod['peak'] = $lowPrice;
            array_push($this->cheapTimes,$lowPeriod);
        }

    }


}    

// get data for tariff
$tomorrowData = new octopusDay($configs);

if ($tomorrowData->rawPrices !== FALSE){
    // got prices

    // seek low and high periods
    $tomorrowData->findPricePeriods($configs['low'],$configs['high']);

    if ((count($tomorrowData->cheapTimes) > 0) || (count($tomorrowData->expensiveTimes) > 0)){
        // there is a low or a high, or both

        // format for email, ugh
        $total_array = array();
        $poMessage = '';
        foreach ($tomorrowData->cheapTimes AS $cheapTime){
            $email_array = (array)$cheapTime;
            $email_array['type'] = "Low";
            array_push($total_array,$email_array);
            $poMessage .= 'Under '.$configs['low'].'p '.$email_array['from'].' to '.$email_array['to'].', ';
        }
        foreach ($tomorrowData->expensiveTimes AS $expTime){
            $email_array = (array)$expTime;
            $email_array['type'] = "High";
            array_push($total_array,$email_array);
            $poMessage .= 'Over '.$configs['high'].'p '.$email_array['from'].' to '.$email_array['to'].', ';
        }
        if (strlen($poMessage) > 2){
            $poMessage = (substr($poMessage, 0, -2)).".";
        }
        
        // email
        $client = new PostmarkClient($configs['postmark']);
        $sendResult = $client->sendEmailWithTemplate(
            "squids_in@mndigital.co",
            "tom@tomroyal.com",
            37708798,
            [
            "body" => "",
            "threshold" => 'Showing prices above '.$configs['high'].'p and below '.$configs['low'].'p',
            "time_periods" => $total_array,
            "subject_line" => "Agile prices for ".$tomorrowData->date->format('d/m/Y'),
            ]);
        
        // push via PushOver
        if ($configs['poToken'] != ''){

            $poTitle = urlencode("Agile prices for ".$tomorrowData->date->format('d/m/Y'));
            $poMessage = urlencode($poMessage);

            $curl2 = curl_init();

            curl_setopt_array($curl2, array(
                CURLOPT_URL => 'https://api.pushover.net/1/messages.json?user='.$configs['poUser'].'&token='.$configs['poToken'].'&message='.$poMessage.'&title='.$poTitle,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
              ));
              
              $po_res = curl_exec($curl2);
              
              curl_close($curl2);
        }
    }

}



?>