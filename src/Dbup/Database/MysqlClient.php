<?php

namespace Dbup\Database;

use Dbup\Exception\RuntimeException;

class MysqlClient {

	private $command = "mysql";

	private $host;
	private $user;
	private $pass;
	private $name;

	public function __construct($host, $user, $pass, $name)
	{
		$this->host = $host;
		$this->user = $user;
		$this->pass = $pass;
		$this->name = $name;

		$return = $this->shellExec("whereis {$this->command}", $out, $err);

		if($return) {
			throw new RuntimeException(trim($err));
		}

		$out = preg_replace("/^{$this->command}:/", '', trim($out));

		if(empty($out)) {
			throw new RuntimeException($this->command . ' is not found.');
		}
	}

	public function exec($file)
	{
		$return = $this->shellExec("MYSQL_PWD={$this->pass} {$this->command} -h{$this->host} -u{$this->user} {$this->name} < {$file}", $out, $err);

		if($return) {
			throw new RuntimeException(trim($err));
		}
	}

	private function shellExec($cmd, &$stdout = null, &$stderr = null) {
		$proc = proc_open($cmd, [
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		], $pipes);

		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);

		return proc_close($proc);
	}

}