<?php
/**
 * @package Newscoop
 * @copyright 2011 Sourcefabric o.p.s.
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

namespace Newscoop\Tools\Console\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Newscoop\Entity\WeatherStat;

/**
 * Update weather command
 */
class UpdateWeatherCommand extends Console\Command\Command
{
    private $config;

    /**
     * @see Console\Command\Command
     */
    protected function configure()
    {
        $this
            ->setName('weather:update')
            ->setDescription('Updates weather information.')
            ->setHelp('Updates weather information.');
    }

    /**
     * @see Console\Command\Command
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = new \Zend_Config_Ini( APPLICATION_PATH . '/configs/meteonews.ini', APPLICATION_ENV);
        $this->config = $config;
 
        $geonamesLists = array(
            'main_regions', 
            'important_regions', 
            //'important_winter_regions', 
            'important_summer_regions', 
            'wanderwetter_regions', 
            'teaser_regions'
        );

        $mexsLists = array(
            //'important_winter_slopes',
        );

        // get data for geonames ids
        foreach ($geonamesLists as $list) {
            foreach ($config->$list as $location) {
                $locationType = 'geonames';
                $data = $this->getApiData('forecasts',$locationType,$location->id,'1h');
                $this->saveForecastData($data,
                    $location->id,
                    $location->name,
                    $locationType,
                    $list,
                    $location->region,
                    $location->elevation
                );
            }
        }
        
        // get data for mexs ids
        foreach ($mexsLists as $list) {
            foreach ($config->$list as $location) {
                $locationType = 'mexs';

                // get forecast data
                $data = $this->getApiData('forecasts',$locationType,$location->id,'1h');
                $this->saveForecastData($data,
                    $location->id,
                    $location->name,
                    'mexs',
                    $list,
                    $location->region,
                    $location->elevation
                );

                // get wintersports data
                $data = $this->getApiData('wintersports',$locationType,$location->id,'1h');
                $this->saveWintersportsData($data,
                    $location->id,
                    $location->name,
                    $locationType,
                    $list,
                    $location->region
                );
            }
        }
        
        // get data for all slopes lists
        foreach ($config->main_regions as $location) {
            $locationType = 'geonames';
            $slopeData = $this->getApiData('wintersports',$locationType,$location->id,'1h');
            $this->saveAllWintersportsData($slopeData,
                'mexs',
                'all_slopes',
                $location->name
            );

            // now load forecast data
            foreach ($slopeData->content as $regions) {
                foreach ($regions as $key => $record) {
                    $locationId = $record["mexs_id"];
                    $locationName = $record["name"];
                        
                    $data = $this->getApiData('forecasts','mexs',$locationId,'1h');
                    $this->saveForecastData($data,
                        $locationId,
                        $locationName,
                        'mexs',
                        'all_slopes',
                        $location->name,
                        $location->elevation
                    );
                }
            } 
        }

	// load mountain evelation forecast data for the next 5 days
        foreach ($config->bergwetter as $location) {
            $locationType = 'mexs';
            $start = date('Y-m-d');
            $end = date('Y-m-d', mktime(0, 0, 0, date('m'), date('d') + 4, date('Y')));
            $data = $this->getApiData('forecasts',$locationType,$location->id,'3h',$start,$end);
            $this->saveForecastData($data,
                $location->id,
                'bergwetter' . $location->elevation,
                'mexs',
                'bergwetter',
                'bergwetter' . $location->elevation,
                $location->elevation
            );
        }

        // delete old records
        $yesterday = date('Y-m-d', mktime(0, 0, 0, date('m'), date('d') - 1, date('Y')));
        $em = \Zend_Registry::get('container')->getService('em');
        $weatherStatRepository = $em->getRepository('Newscoop\Entity\WeatherStat'); 
        $weatherStatRepository->deleteByDate($yesterday);
	 
    }

    protected function getApiData($feed,$locationType,$locationId,$cumulation,$begin = null,$end = null)
    {
        $url = "http://mmv.feeds.meteonews.net/$feed/$locationType/$locationId.xml";
        $user = $this->config->api_user;
        $pass = $this->config->api_pass;
	$parameters = array( 'cumulation' => $cumulation, 'lang' => 'de');
        if ($begin) {
            $parameters['begin'] = $begin;
        }
        if ($end) {
            $parameters['end'] = $end;
        }

        $client = new \Zend_Http_Client($url);
        $client->setMethod(\Zend_Http_Client::GET);
        $client->setAuth($user,$pass, \Zend_Http_Client::AUTH_BASIC);
        $client->setParameterGet($parameters);
        $body = $client->request()->getBody();

        if ($body) {
            return simplexml_load_string($body);
        } else {
            return false;
        }
    }

    protected function saveForecastData($xml,$locationId,$locationName,$locationType,$locationList,$regionName,$locationElevation)
    {
        $em = \Zend_Registry::get('container')->getService('em');
        $weatherStatRepository = $em->getRepository('Newscoop\Entity\WeatherStat'); 

        foreach ($xml->content as $timeperiods) {
            foreach ($timeperiods as $key => $record) {
                $time = date_parse($record["end_datetime"]);
                $hour = $time['hour'];
		$date = $time['year'] . '-' . $time['month'] . '-' . $time['day'];

                $searchBy = array('locationId' => $locationId, 'locationList' => $locationList, 'statDate' => new \DateTime($date), 'hour' => $hour);

                if ($weatherStatRepository->countBy($searchBy) > 0) {
                    $weatherStat = $weatherStatRepository->findOneBy($searchBy);
                } else { 
                    $weatherStat = new WeatherStat();
                }
                $values = array(
                    'location_id' => $locationId,
                    'location_type' => (string)$locationType,
                    'location_name' => (string)$locationName,
                    'location_list' => (string)$locationList,
                    'region_name' => (string)$regionName,
		    'stat_date' =>  new \DateTime($date),
                    'hour' => (int)$hour,
                    'symbol' => (int)($record->symb) ? $record->symb : 0,
                    'temperature' => (int)($record->temp) ? $record->temp : 0,
                    'temperature_min' => (int)($record->temp_min) ? $record->temp_min : 0,
                    'temperature_max' => (int)($record->temp_max) ? $record->temp_max : 0,
                    'precip' => (int)($record->precip) ? $record->precip : 0,
                    'winddir' => (int)($record->winddir) ? $record->winddir : 0,
                    'windforce' => (int)($record->windforce) ? $record->windforce : 0,
                    'elevation' => (int)$locationElevation
                );
                $weatherStatRepository->save($weatherStat, $values);
                $weatherStatRepository->flush();
            }
        }
    }

    protected function saveWintersportsData($xml,$locationId,$locationName,$locationType,$locationList,$regionName)
    {
        $em = \Zend_Registry::get('container')->getService('em');
        $weatherStatRepository = $em->getRepository('Newscoop\Entity\WeatherStat'); 

        foreach ($xml->content as $regions) {
            foreach ($regions as $key => $record) {
                if ($record["mexs_id"] == $locationId) {
                    // save slope data for every hour
                    for ($hour = 0; $hour <= 23; $hour++) {
                        $searchBy = array('locationId' => $locationId, 'hour' => $hour, 'locationList' => $locationList);

                        if ($weatherStatRepository->countBy($searchBy) > 0) {
                            $weatherStat = $weatherStatRepository->findOneBy($searchBy);
                        } else { 
                            $weatherStat = new WeatherStat();
                        }
                        $values = array(
                            'location_id' => $locationId,
                            'location_type' => (string)$locationType,
                            'location_name' => (string)$locationName,
                            'location_list' => (string)$locationList,
                            'region_name' => (string)$regionName,
                            'hour' => $hour,
                            'snow_condition' => $record->ski->snow_condition,
                            'slope_condition' => $record->ski->condition,
                            'total_slopes' => (int)($record->ski->facilities["total"]) ? $record->ski->facilities["total"] : 0,
                            'open_slopes' => (int)($record->ski->facilities) ? $record->ski->facilities : 0
                        );
                        $weatherStatRepository->save($weatherStat, $values);
                        $weatherStatRepository->flush();
                    }
                } else {
                    continue;
                }
            }
        }
    }
    
    protected function saveAllWintersportsData($xml,$locationType,$locationList,$regionName)
    {
        $em = \Zend_Registry::get('container')->getService('em');
        $weatherStatRepository = $em->getRepository('Newscoop\Entity\WeatherStat'); 

        foreach ($xml->content as $regions) {
            foreach ($regions as $key => $record) {
                $locationId = $record["mexs_id"];
                $locationName = $record["name"];

                // save slope data for every hour
                for ($hour = 0; $hour <= 23; $hour++) {
                    $searchBy = array('locationId' => $locationId, 'hour' => $hour, 'locationList' => $locationList);

                    if ($weatherStatRepository->countBy($searchBy) > 0) {
                        $weatherStat = $weatherStatRepository->findOneBy($searchBy);
                    } else { 
                        $weatherStat = new WeatherStat();
                    }
                    $values = array(
                        'location_id' => $locationId,
                        'location_type' => (string)$locationType,
                        'location_name' => (string)$locationName,
                        'location_list' => (string)$locationList,
                        'region_name' => (string)$regionName,
                        'hour' => $hour,
                        'snow_condition' => $record->ski->snow_condition,
                        'slope_condition' => $record->ski->condition,
                        'total_slopes' => (int)($record->ski->facilities["total"]) ? $record->ski->facilities["total"] : 0,
                        'open_slopes' => (int)($record->ski->facilities) ? $record->ski->facilities : 0
                    );
                    $weatherStatRepository->save($weatherStat, $values);
                    $weatherStatRepository->flush();
                }
            }
        }
    }
}
