<?php
/**********************************************************************************
*
*    #####
*   #     # #####   ##   ##### #    #  ####  ###### #    #  ####  # #    # ######
*   #         #    #  #    #   #    # #      #      ##   # #    # # ##   # #
*    #####    #   #    #   #   #    #  ####  #####  # #  # #      # # #  # #####
*         #   #   ######   #   #    #      # #      #  # # #  ### # #  # # #
*   #     #   #   #    #   #   #    # #    # #      #   ## #    # # #   ## #
*    #####    #   #    #   #    ####   ####  ###### #    #  ####  # #    # ######
*
*                            the missing event broker
*                               Perfdata Extension
*
* --------------------------------------------------------------------------------
*
* Copyright (c) 2014 - present Daniel Ziegler <daniel@statusengine.org>
*
* This program is free software; you can redistribute it and/or
* modify it under the terms of the GNU General Public License
* as published by the Free Software Foundation in version 2
* 
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
* 
* You should have received a copy of the GNU General Public License
* along with this program; if not, write to the Free Software
* Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*
* --------------------------------------------------------------------------------
*
* This extension for statusengine is able to parse performance data and create or update
* graphs based on rrdtool. So you dont need to install any additional software
* to get this job done
*
**********************************************************************************/

class PerfdataTask extends AppShell{
	
	public $Config = [];
	public $Logfile = null;
	
	public $servicestate = [
		0 => 'OK',
		1 => 'WARNING', 
		2 => 'CRITICAL',
		3 => 'UNKNOWN'
	];
	
	public function init($Config, $Logfile){
		App::uses('File', 'Utility');
		$this->Logfile = $Logfile;
		$this->Config = $Config;
	}
	
	/**
	 * Parse perfdata of the naemon plugin output to an array
	 *
	 * @since 1.0.0
	 * @author Daniel Ziegler <daniel@statusengine.org>
	 *
	 * @return void
	 */
	function parsePerfdataString($perfdataString){
		/* $perfdataString should looks like this
		rta=0.069000ms;100.000000;500.000000;0.000000 pl=0%;20;60;0
		 */
		$perfdata = [];
		$arrayKeys = [
			'current', 'unit', 'warning', 'critical', 'min', 'max'
		];
		
		$defaultValues = [
			'current' => null,
			'unit' => null,
			'warning' => null,
			'critical' => null,
			'min' => null,
			'max' => null
		];
		
		foreach(explode(" ", $perfdataString) as $dataSource){
			$i = 2;
			foreach(explode(';', $dataSource) as $value){
				if(preg_match('/=/', $value)){
					$s = preg_split('/=/', $value);
					//Fetch unit
					$current = '';
					$unit = '';
					foreach(str_split($s[1]) as $char ){
						if( $char == '.' || $char == ',' || ($char >= '0' && $char <= '9') ){
							$current .= $char;
						}else{
							$unit .= $char;
						}
					}
					
					if($unit == '%'){
						$unit = '%%';
					}
					
					$perfdata[$s[0]][$arrayKeys[0]] = str_replace(',', '.', $current);
					$perfdata[$s[0]][$arrayKeys[1]] = $unit;
					continue;
				}
				
				$perfdata[$s[0]][$arrayKeys[$i]] = $value;
				$i++;
			}
			unset($s);
		}
		
		//Fil up missing fields in array
		foreach($perfdata as $dataSource => $values){
			$perfdata[$dataSource] = array_merge($defaultValues, $perfdata[$dataSource]);
		}

		return $perfdata;
	}
	
	public function writeToRrd($parsedPerfdataString, $parsedPerfdata){
		$replacedHostname = preg_replace($this->Config['replace_characters'], '_', $parsedPerfdataString['HOSTNAME']);
		$replacedServicename = preg_replace($this->Config['replace_characters'], '_', $parsedPerfdataString['SERVICEDESC']);
		
		
		if(!is_dir($this->Config['PERFDATA']['dir'].$replacedHostname)){
			mkdir($this->Config['PERFDATA']['dir'].$$replacedHostname);
		}
		
		$perfdataFile = $this->Config['PERFDATA']['dir'].$replacedHostname.'/'.$replacedServicename.'.rrd';
		$error = '';
		$return = true;
		
		if(file_exists($perfdataFile)){
			$options = [];
			
			$options[] = $parsedPerfdataString['TIMET'];
			
			foreach($parsedPerfdata as $ds => $data){
				$options[] = $data['current'];
			}

			
			if($this->Config['RRDCACHED']['use'] === true){
				if(!rrd_update($perfdataFile, [implode(':', $options), '--daemon='.$this->Config['RRDCACHED']['sock']])){
					$this->out('Error on updating RRD');
					$return = false;
					$error = rrd_error();
					$this->Logfile->stlog($error);
					//debug($error);
				}
			}else{
				if(!rrd_update($perfdataFile, [implode(':', $options)])){
					$this->out('Error on updating RRD');
					$return = false;
					$error = rrd_error();
					$this->Logfile->stlog($error);
					//debug($error);
				}
			}
		}else{
			//RRA:AVERAGE:0.5:1:576000 RRA:MAX:0.5:1:576000 RRA:MIN:0.5:1:576000 DS:1:GAUGE:8460:U:U --start=1431375240 --step=60
			//RRA:AVERAGE:0.5:1:576000 RRA:MAX:0.5:1:576000 RRA:MIN:0.5:1:576000 DS:1:GAUGE:8460:U:U DS:2:GAUGE:8460:U:U --start=1431375345 --step=60
			$options = [];
			$options[] = 'RRA:AVERAGE:'.$this->Config['RRA']['average'];
			$options[] = 'RRA:MAX:'.$this->Config['RRA']['max'];
			$options[] = 'RRA:MIN:'.$this->Config['RRA']['min'];
			
			$dataSourceCount = 1;
			foreach($parsedPerfdata as $ds => $data){
				if(isset($this->Config['RRD']['DATATYPE'][$data['unit']])){
					$options[] = 'DS:'.$dataSourceCount.':'.$this->Config['RRD']['DATATYPE'][$data['unit']].':8460:U:U';
				}else{
					$options[] = 'DS:'.$dataSourceCount.':'.$this->Config['RRD']['DATATYPE']['default'].':8460:U:U';
				}
				$dataSourceCount++;
			}
			
			$options[] = '--start='.$parsedPerfdataString['TIMET'];
			$options[] = '--step='.$this->Config['RRA']['step'];
			
			if(!rrd_create($perfdataFile, $options)){
				$this->out('Error on createing RRD');
				$return = false;
				$error = rrd_error();
				$this->Logfile->stlog($error);
				//debug($error);
			}
		}
		
		return [
			'return' => $return,
			'error' => $error
		];
		
	}
	
	public function updateXML($parsedPerfdataString, $parsedPerfdata, $rrdReturn){
		$replacedHostname = preg_replace($this->Config['replace_characters'], '_', $parsedPerfdataString['HOSTNAME']);
		$replacedServicename = preg_replace($this->Config['replace_characters'], '_', $parsedPerfdataString['SERVICEDESC']);
		
		$xmlFile = new File($this->Config['PERFDATA']['dir'].$replacedHostname.'/'.$replacedServicename.'.xml');
		if(!$xmlFile->exists()){
			$xmlFile->create();
		}
		
		if($this->Config['XML']['delay'] > 0){
			if((time() - $xmlFile->lastChange()) < $this->Config['XML']['delay']){
				return false;
			}
		}
		
		$xml = "";

$xml .= "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>
<NAGIOS>";
$dataSourceCounter = 1;
$template = $this->parseCheckCommand($parsedPerfdataString['SERVICECHECKCOMMAND'])[0];
foreach($parsedPerfdata as $ds => $data){
$warnThresholds = $this->thresholds($data['warning'], 'warn');
$critThresholds = $this->thresholds($data['critical'], 'crit');
$xml.="  <DATASOURCE>
    <TEMPLATE>".$template."</TEMPLATE>
    <RRDFILE>".$this->Config['PERFDATA']['dir'].$replacedHostname.'/'.$replacedServicename.".rrd</RRDFILE>
    <RRD_STORAGE_TYPE>SINGLE</RRD_STORAGE_TYPE>
    <RRD_HEARTBEAT>".$this->Config['RRD']['heartbeat']."</RRD_HEARTBEAT>
    <IS_MULTI>0</IS_MULTI>
    <DS>".$dataSourceCounter."</DS>
    <NAME>".$ds."</NAME>
    <LABEL>".$ds."</LABEL>
    <UNIT>".$data['unit']."</UNIT>
    <ACT>".$data['current']."</ACT>
    <WARN>".$warnThresholds['warn']."</WARN>
    <WARN_MIN>".$warnThresholds['warn_min']."</WARN_MIN>
    <WARN_MAX>".$warnThresholds['warn_max']."</WARN_MAX>
    <WARN_RANGE_TYPE></WARN_RANGE_TYPE>
    <CRIT>".$critThresholds['crit']."</CRIT>
    <CRIT_MIN>".$critThresholds['crit_min']."</CRIT_MIN>
    <CRIT_MAX>".$critThresholds['crit_max']."</CRIT_MAX>
    <CRIT_RANGE_TYPE></CRIT_RANGE_TYPE>
    <MIN>".$data['min']."</MIN>
    <MAX>".$data['max']."</MAX>
  </DATASOURCE>";
  $dataSourceCounter++;
}

$xml.="  <RRD>
    <RC>".(int)$rrdReturn['return']."</RC>
    <TXT>".($rrdReturn['return']?'successful updated':$rrdReturn['error'])."</TXT>
  </RRD>
  <NAGIOS_AUTH_HOSTNAME></NAGIOS_AUTH_HOSTNAME>
  <NAGIOS_AUTH_SERVICEDESC></NAGIOS_AUTH_SERVICEDESC>
  <NAGIOS_CHECK_COMMAND>".$parsedPerfdataString['SERVICECHECKCOMMAND']."</NAGIOS_CHECK_COMMAND>
  <NAGIOS_DATATYPE>".$parsedPerfdataString['DATATYPE']."</NAGIOS_DATATYPE>
  <NAGIOS_DISP_HOSTNAME>".$parsedPerfdataString['HOSTNAME']."</NAGIOS_DISP_HOSTNAME>
  <NAGIOS_DISP_SERVICEDESC>".$parsedPerfdataString['SERVICEDESC']."</NAGIOS_DISP_SERVICEDESC>
  <NAGIOS_HOSTNAME>".$parsedPerfdataString['HOSTNAME']."</NAGIOS_HOSTNAME>
  <NAGIOS_HOSTSTATE></NAGIOS_HOSTSTATE>
  <NAGIOS_HOSTSTATETYPE></NAGIOS_HOSTSTATETYPE>
  <NAGIOS_MULTI_PARENT></NAGIOS_MULTI_PARENT>
  <NAGIOS_PERFDATA>".$parsedPerfdataString['SERVICEPERFDATA']."</NAGIOS_PERFDATA>
  <NAGIOS_RRDFILE>".$this->Config['PERFDATA']['dir'].$replacedHostname.'/'.$replacedServicename.".rrd</NAGIOS_RRDFILE>
  <NAGIOS_SERVICECHECKCOMMAND>".$parsedPerfdataString['SERVICECHECKCOMMAND']."</NAGIOS_SERVICECHECKCOMMAND>
  <NAGIOS_SERVICEDESC>".$parsedPerfdataString['SERVICEDESC']."</NAGIOS_SERVICEDESC>
  <NAGIOS_SERVICEPERFDATA>".$parsedPerfdataString['SERVICEPERFDATA']."</NAGIOS_SERVICEPERFDATA>
  <NAGIOS_SERVICESTATE>".$this->servicestate[$parsedPerfdataString['SERVICESTATE']]."</NAGIOS_SERVICESTATE>
  <NAGIOS_SERVICESTATETYPE>".($parsedPerfdataString['SERVICESTATETYPE'] == 1 ? 'HARD' : 'SOFT')."</NAGIOS_SERVICESTATETYPE>
  <NAGIOS_TIMET>".$parsedPerfdataString['TIMET']."</NAGIOS_TIMET>
  <NAGIOS_XMLFILE>".$this->Config['PERFDATA']['dir'].$replacedHostname.'/'.$replacedServicename.".xml</NAGIOS_XMLFILE>
  <XML>
   <VERSION>4</VERSION>
  </XML>
</NAGIOS>";

	$xmlFile->write($xml);
	$xmlFile->close();

	}
	
	/**
	 * Parse the check_command string into command_name and command_arg
	 *
	 * @since 1.0.0
	 * @author Daniel Ziegler <daniel@statusengine.org>
	 *
	 * @param  string checkCommand from $payload
	 * @return array  [0] => 'lan_ping', [1] => '!80!80'
	 */
	public function parseCheckCommand($checkCommand){
		$cc = explode('!', $checkCommand, 2);
		$return = [];
		if(isset($cc[0])){
			$return[0] = $cc[0];
		}else{
			$return[0] = '';
		}
		if(isset($cc[1])){
			$return[1] = $cc[1];
		}else{
			$return[1] = '';
		}
		return $return;
	}
	
	/**
	 * Parse the thresholds like 50:100 to warn_min and warn_max
	 *
	 * @since 1.1.0
	 * @author Daniel Ziegler <daniel@statusengine.org>
	 *
	 * @param  string thresholds
	 * @param  string key for the return array
	 * @return array  [warn] => null, [warn_min] => 50, [warn_max] => 100
	 */
	public function thresholds($threshold, $key = 'warn'){
		$result = explode(':', $threshold);
		
		if(sizeof($result) == 1){
			$return = [
				$key => $result[0],
				$key.'_min' => null,
				$key.'_max' => null
			];
		}else{
			$return = [
				$key => null,
				$key.'_min' => $return[0],
				$key.'_max' => $return[1]
			];
		}
		
		return $return;
	}
}