<?php declare(strict_types=1);

/*
	Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 2 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresoftware\Telemetry;

use ErrorException;
use Exception;
use OpenMetrics\Exposition\Text\Collections\CounterCollection;
use OpenMetrics\Exposition\Text\Metrics\Counter;
use OpenMetrics\Exposition\Text\Types\Label;
use OpenMetrics\Exposition\Text\Types\MetricName;

/**
 * For more information of the exporter check the url
 * https://github.com/manticoresoftware/openmetrics
 *
 * Usage:
 * <code>
 * 	$metric = new Metric('127.0.0.1', labels: ['version' => '1.0', 'columnar' => '5.2.3']);
 * 	$metric->add('metric1', 1);
 * 	$metric->add('metric1', 13);
 * 	$metric->add('metric1', 156);
 * 	$metric->add('metric2', 1000);
 * 	$metric->add('metric2', 1000);
 * 	if ($metric->send()) {
 * 		echo 'OK';
 * 	}
 * </code>
 */
final class Metric {
	const PROTO = 'https';
	const HOST = 'telemetry.manticoresearch.com';
	const PORT = 443;

	// The writing path for prometheus metrics
	const API_PATH = '/api/v1/import/prometheus';

	// Request timeout in seconds
	const REQUEST_TIMEOUT = 1;

	/** @var array<string,CounterCollection> */
	protected array $metrics = [];

	/** @var array<int,Label> */
	protected array $labels = [];

	/**
	 * Initialize Metric with host and port to Prometheus
	 *
	 * @param array<string,string> $labels
	 * 	Optional labels if we need to attach it to every metric we register
	 * @return void
	 */
	public function __construct(array $labels = []) {
		// Add default labels first
		$osName = php_uname('s');
		$osRelease = static::parseOsReleaseFile();
		$labels['os_name'] = $osName;
		$lables['os_release_name'] = $osRelease['name'] ?? 'unknown';
		$lables['os_release_version'] = $osRelease['version'] ?? 'unknown';
		$labels['machine_type'] = php_uname('m');
		$labels['machine_id'] = static::getMachineId($osName);
		$labels['dockerized'] = static::isDockerized();
		$lables['official_docker'] = static::isOfficialDocker();
		$labels['arch'] = static::getArchitecture();
		// And finally add all labels
		$this->addLabelList($labels);
	}

	/**
	 * Add single label to the current instance that will be used for all metrics
	 *
	 * @param string $name
	 * @param string $value
	 * @return $this
	 */
	public function addLabel(string $name, string $value): static {
		$this->labels[] = Label::fromNameAndValue($name, $value);
		return $this;
	}

	/**
	 * Add list of labels for single call
	 *
	 * @param array<string,string> $labels
	 * @return $this
	 */
	public function addLabelList(array $labels): static {
		foreach ($labels as $name => $value) {
			$this->addLabel($name, $value);
		}
		return $this;
	}

	/**
	 * Method to reset labels to reassign it
	 * @return static
	 */
	public function resetLabels(): static {
		$this->labels = [];
		return $this;
	}

	/**
	 * Helper to update current set lables
	 * @param  array<string,string>  $labels
	 * @return static
	 */
	public function updateLabels(array $labels): static {
		// 1. Clean up labels with the same name
		foreach ($this->labels as $idx => $label) {
			if (!isset($labels[$label->getName()])) {
				continue;
			}

			unset($this->labels[$idx]);
		}

		// Keep list without empty indexes
		$this->labels = array_values($this->labels);

		// 2. Add labels with new values to end of th elist
		$this->addLabelList($labels);
		return $this;
	}

	/**
	 * Return all set labels
	 * @return array<string,string>
	 */
	public function getLabels(): array {
		return array_reduce(
			$this->labels, function ($carry, $label) {
				$carry[$label->getName()] = $label->getValue();
				return $carry;
			}, []
		);
	}

	/**
	 * Register a metric that will be send on calling send method
	 *
	 * @param string $name
	 * 	The name of the metric
	 * @param int|float $value
	 * 	Number value of the metric
	 * @return static
	 */
	public function add(string $name, int|float $value): static {
		$counter = Counter::fromValue($value);
		if ($this->labels) {
			$counter->withLabels(...$this->labels);
		}

		if (isset($this->metrics[$name])) {
			$this->metrics[$name]->add($counter);
			return $this;
		}

		// We recieved this metric for the first time
		$this->metrics[$name] = CounterCollection::fromCounters(
			MetricName::fromString($name),
			$counter
		);

		return $this;
	}

	/**
	 * Send registered batch of metrics to the server
	 *
	 * @return bool
	 */
	public function send(): bool {
		$body = '';
		/** @var CounterCollection $collection */
		foreach ($this->metrics as $k => $collection) {
			$body .= $collection->getMetricsString() . PHP_EOL;
			unset($this->metrics[$k]);
		}

		return $this->process($body);
	}

	/**
	 * Helper function to make request and send data to server
	 *
	 * @param string $body
	 * @return bool
	 * @throws Exception
	 */
	protected function process(string $body): bool {
		$content = gzencode($body, 6);
		if ($content === false) {
			throw new Exception('Failed to gzip data to send');
		}

		$opts = [
			'http' => [
				'method'  => 'POST',
				'header'  => "Content-Encoding: gzip\n"
					. "Content-Type: application/x-www-form-urlencoded\n"
			. 'Content-Length: '. strlen($content),
				'content' => $content,
				'ignore_errors' => false,
				'timeout' => static::REQUEST_TIMEOUT,
			],
		];

		$context = stream_context_create($opts);
		try {
			$result = file_get_contents(
				static::PROTO . '://' . static::HOST . ':' . static::PORT . static::API_PATH,
				false,
				$context
			);
		} catch (ErrorException) {
			return false;
		}

		return $result === '';
	}

	/**
	 * Helper to get machine id by operating system name
	 *
	 * @param string $osName
	 * @return string Default is unknown
	 *  We also hash the result with manticore prefix as sha256
	 */
	protected static function getMachineId(string $osName): string {
		$machineId = match ($osName) {
			'Darwin' => exec('ioreg -rd1 -c IOPlatformExpertDevice | awk \'/IOPlatformSerialNumber/ {print $3}\''),
			'Linux', 'Unix' => exec(
				'( cat /var/lib/dbus/machine-id /etc/machine-id /etc/hostname '
				. '2> /dev/null || hostname 2> /dev/null )'
				. ' | head -n 1 || :'
			),
			'FreeBSD', 'NetBSD', 'OpenBSD' => exec(
				'kenv -q smbios.system.uuid || sysctl -n kern.hostuuid'
			),
			'WINNT', 'WIN32', 'Windows' => exec(
				'%windir%\System32\REG.exe QUERY HKEY_LOCAL_MACHINE\SOFTWARE\Microsoft\Cryptography /v MachineGuid'
			),
			default => '',
		};

		return $machineId ? hash('sha256', "manticore:$machineId") : 'unknown';
	}

	/**
	 * Get information if we run inside container or not
	 * It can return one of: unknown, yes, no
	 * @return string
	 */
	protected static function isDockerized(): string {
		// If there is no such path, probably we are not on linux
		// That means we are not inside container also
		if (!file_exists('/proc/1/sched')) {
			return 'unknown';
		}

		$resultCode = 0;
		exec("awk '{exit (\$1 ~ /^init|systemd$/)}' /proc/1/sched", result_code: $resultCode);
		return $resultCode === 0 ? 'yes' : 'no';
	}

	/**
	 * Detect if we are running inside official docker image
	 * @return string
	 */
	protected static function isOfficialDocker(): string {
		$daemonUrl = getenv('DAEMON_URL') ?: '';
		return str_contains($daemonUrl, 'manticore') ? 'yes' : 'no';
	}

	/**
	 * Get current machine architecture. In case if we fail
	 * @return string
	 */
	protected static function getArchitecture(): string {
		$uname = php_uname('m');
		$arch = 'unknown';

		if (stripos($uname, 'x86_64') !== false
			|| stripos($uname, 'amd64') !== false
			|| stripos($uname, 'x64') !== false
		) {
			$arch = 'amd';
		} elseif (stripos($uname, 'arm') !== false) {
			$arch = 'arm';
		}

		return $arch;
	}

	/**
	 * Parse the release file if presents and return info
	 * @return array<string,string>
	 */
	protected static function parseOsReleaseFile(): array {
		$filename = '/etc/os-release';

		if (!file_exists($filename) || !is_readable($filename)) {
			return [];
		}

		// Read the file into an array of lines
		$lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!$lines) {
			return [];
		}

		// Initialize an associative array to hold the variables
		$osInfo = [];
		foreach ($lines as $line) {
			if (false === strpos($line, '=')) {
				continue;
			}
			[$key, $value] = explode('=', $line, 2);
			$osInfo[strtolower($key)] = trim($value, '"');
		}

		return $osInfo;
	}
}
