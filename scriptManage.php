<?php

class scriptManage
{
	private $params;
	private $swoole_table;
    private $reloadPid;
	
	public function __construct()
	{
		//解析参数
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
	
	//初始化内存
	private function iniMemory()
	{
		$this->swoole_table = new \Swoole\Table(500);
		$this->swoole_table->column('name', swoole_table::TYPE_STRING, 50);
		$this->swoole_table->column('pipe', swoole_table::TYPE_INT, 10);
		$this->swoole_table->create();		
	}
	
	
	/**
	* 开始
	**/
	private function start()
	{
		swoole_set_process_name('scrimage');
		$this->iniMemory();

		//信号监听
		swoole_process::signal(SIGCHLD, function($sig) {
			
			  while($ret = swoole_process::wait(false)) {
				  
				  if ($result = $this->swoole_table->get($ret['pid'])) {
					  //结束日志监听
					  swoole_event_del($result['pipe']);
                      
                      //删除缓存
                      $this->swoole_table->del($ret['pid']);
                      
                      //重启进程
                      if ($config = $this->getConfig($result['name'])) {
                        $this->log("restart process " . $result['name']);
                        $this->setProcess($config);
                      }
                      
				  } elseif ($ret['pid'] == $this->reloadPid) {
                      $this->log("restart reload ");
                      $this->reload();
                  }
			  }
		});
		
        $this->reload();
	}
    
    /**
    * 生成进程
    */
    private function setProcess($config)
    {			
		if (!$this->ifRunning($config['name'])) {
            $process = new swoole_process(function($worker) use ($config) {
                
                swoole_set_process_name($config['name']);
                $worker->exec($config['exec_file'], $config['content']);
                
            }, true);
            $process->start();
            $this->swoole_table->set($process->pid, array(
                'name' => $config['name'],
                'pipe' => $process->pipe,
            ));
            
            //写入日志
            swoole_event_add($process->pipe, function($pipe) use ($process, $config) {                
                $recv = $process->read();
                if ($recv) {
                    file_put_contents($config['log_path'], $recv, FILE_APPEND);
                }
            });
           
            $this->log("set process " . $config['name'] . " success");
		} else {
            $this->log("{$config['name']} is running");
        }
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
	*/
	private function ifRunning($name)
	{
        foreach ($this->swoole_table as $row => $value) {
            if ($value['name'] == $name) {
                return true;
            }
        }
        return false;
	}
	
	/**
	* 重刷
	*
	*/
	private function reload()
	{
        $process = new swoole_process(function($worker) {
            while (true) {
                if ($data = $this->getConfig()) {
                    foreach ($data as $v) {
                        if (!$this->ifRunning($v['name'])) {
                            $worker->write(json_encode($v));
                        }
                    }
                }
                
                sleep(60);
            }
        });
        $process->start();
        
        $this->reloadPid = $process->pid;
        swoole_event_add($process->pipe, function($pipe) use ($process) {
            
            $recv = $process->read();
            if ($data = json_decode($recv, true)) {
                $this->log("start process " . $data['name']);
                $this->setProcess($data);
            } else {
                $this->log("can't parse config " . $recv);
            }        
            
        }); 

	}
    
    /**
    *获取配置
				'exec_file' => '/usr/local/php-7.1.8/bin/php',
				'type' => 'command',
				'content' => array('/work/test/test.php'),
				'log_path' => '/tmp/test.log',
				'name' => 'test'
    
    */
    private function getConfig($name = false)
    {
        $content = file_get_contents('/tmp/config');
        $datas = json_decode($content, true);
        
        if ($name) {
            foreach ($datas as $data) {
                if ($data['name'] == $name) {
                    return $data;
                }
            }
        } else {
            return $datas;
        }
    }
	
	private function log($msg)
	{
		echo date('Y-m-d H:i:s ') . $msg . PHP_EOL;
	}
	
	
}

$script = new scriptManage();
$script->run();

