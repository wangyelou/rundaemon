<?php

class scriptManage
{
	private $params;
	
	public function __construct()
	{
		$this->params = $this->parseParam();
	}
	
	public function run() 
	{
		if (isset($this->params['s'])) {
			
			if (method_exists($this, $this->params['s'])) {
				
				call_user_func(array($this, $this->params['s']));
				
			} else {
				$this->log('not find method '. $this->$this->params['s']);								
			}			
			
		} else {
			//守护进程
			$this->scrimaged();
		}	
		
	}
	
	//守护进程
	private function scrimaged()
	{
		swoole_set_process_name('scrimaged');
		
		while (true) {
			$outPuts = array();
			exec("ps -ef|grep 'scrimage'|grep -v 'scrimaged'|grep -v 'grep'|wc -l", $outPuts);
			
			if ($outPuts[0] == 0) {
				system('nohup php scriptManage.php -s start >> /dev/null 2>&1 &');			
				
			} elseif ($outPuts[0] > 1) {
				system("ps -ef|grep 'scrimage'|grep -v 'scrimaged'|grep -v 'grep'|awk '{print $2}'|xargs kill -9");
				continue;
			}
			sleep(60);
		}
	}
	
	
	/**
	* 开始
	**/
	private function start()
	{
		swoole_set_process_name('scrimage');
		
		foreach ($this->getConfig() as $config) {			
			if (!$this->ifRunning($config['run_id'])) {
							
				$process = new swoole_process(function($worker) use ($config) {
					
					swoole_set_process_name('test');
					$worker->exec($config['exec_file'], $config['content']);
					//system("{$config['exec_file']} {$config['content']}");
					
				}, true);
				$process->start();
				
				//写入日志
				swoole_event_add($process->pipe, function($pipe) use ($process) {
					
					$recv = $process->read();
					if ($recv) {
						
						var_dump($recv);	
						
					}
				});
			}
		}		
		
		//信号监听
		swoole_process::signal(SIGCHLD, function($sig) {
			
			  while($ret =  swoole_process::wait(false)) {
				  echo "PID={$ret['pid']}\n";
			  }
		  
		});
	}
	
	/**
	* 获取参数
	*/
	private function parseParam()
	{
		if (!$this->params) {
			$argv = $_SERVER['argv'];
			for ($i=1; $i<count($argv); $i++) {
				if (strpos($argv[$i], '-') === 0) {
					$this->params[ltrim($argv[$i], '-')] = $argv[++$i];				
				}			
			}
		}
		
		return $this->params;		
	}
	
	
	/**
	* 判断是否正在运行
	**/
	private function ifRunning($runId)
	{
		return false;		
	}
	
	/**
	* 获取配置
	*
	**/
	private function getConfig()
	{
		return array(
			array(
				'run_id' => '1137eecda98fadab99487b0e3f52339f',
				'exec_file' => '/usr/local/php-7.1.8/bin/php',
				'type' => 'command',
				'content' => array('/work/test/test.php'),
				'log_path' => '/tmp/test.log'
			)
		
		);		
	}
	
	private function log($msg)
	{
		echo $msg . PHP_EOL;
	}
	
	
}

$script = new scriptManage();
$script->run();

