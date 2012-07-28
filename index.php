<?php
class AyMap
{
	// This is used only to graphically depict data.
	public $container	= NULL;
	
	private $map		= NULL;
	
	public function __construct($map, $container = NULL)
	{
		$this->container			= $container;
	
		$this->map					= $map;
		
		// Prepare the constant values required to calculate the orthodromic distance.
		$this->map['lon_delta']		= $map['lon2']-$map['lon1'];

		$this->map['degree']		= $map['lat2'] * M_PI / 180;
		
		$this->map['world_width']	= (($this->container['width'] / $this->map['lon_delta']) * 360) / (2 * M_PI);
		
		$this->map['offset_y']		= ($this->map['world_width'] / 2 * log((1 + sin($this->map['degree'])) / (1 - sin($this->map['degree']))));	   
	}
	
	/**
	 * Given the WGS84 coordinates the function will return
	 * coordinates projection on the X,Y axis.
	 *
	 * http://en.wikipedia.org/wiki/World_Geodetic_System
	 * http://en.wikipedia.org/wiki/Mercator_projection
	 */
	public function getProjection($lat, $lon)
	{
		if($this->container === NULL)
		{
			throw new AyMapException('Cannot calculate projection without the container dimensions.');
		}
		
		$x		= ($lon - $this->map['lon1']) * ($this->container['width'] / $this->map['lon_delta']);
	
	    $lat	= $lat * M_PI / 180;
	    
	    $y		= $this->container['height'] - (($this->map['world_width'] / 2 * log((1 + sin($lat)) / (1 - sin($lat)))) - $this->map['offset_y']);
	
	    return array($x, $y);
	}
	
	/**
	 * Gives the shortest distance between two coordinates
	 * taking into account Earth's oblate spheroid shape.
	 * 
	 * http://en.wikipedia.org/wiki/Great-circle_distance
	 * http://snipplr.com/view.php?codeview&id=2531
	 */
	public function getOrthodromicDistance($lat1, $lng1, $lat2, $lng2)
	{
		$pi80	= M_PI / 180;
		
		$lat1	*= $pi80;
		$lng1	*= $pi80;
		$lat2	*= $pi80;
		$lng2	*= $pi80;
	
		$dlat	= $lat2 - $lat1;
		$dlng	= $lng2 - $lng1;
		
		$a		= sin($dlat / 2) * sin($dlat / 2) + cos($lat1) * cos($lat2) * sin($dlng / 2) * sin($dlng / 2);
		
		$c		= 2 * atan2(sqrt($a), sqrt(1 - $a));
		
		return 6371.01 * $c;
	}
}

class AyMapTest
{
	private $map		= NULL;
	
	public function __construct(AyMap $map)
	{
		$this->map	= $map;
	}

	public function dump($data)
	{
		header('Content-Type: text-plain');
		
		echo $this->explain($data);
		
		exit;
	}
	
	public function draw($data)
	{
		if(!is_writable('map.png'))
		{
			throw new AyMapException('PHP cannot write to the map.png file.');
		}
	
		// Note that I aware of Imagick php extension.
		// However, I simply feel more comfortable with the CLI interface.
		// http://www.php.net/manual/en/book.imagick.php	
		shell_exec($this->explain($data));
		
		header('Content-Type: image/png');
		
		readfile('map.png');
		
		exit;
	}
	
	private function explain($data)
	{	
		foreach($data as $entry)
		{
			$coordinate	= $this->map->getProjection($entry[0], $entry[1]);
			
			$coordinates[]	= implode(',', $coordinate);
		}
		
		$im		= array('convert -size ' . escapeshellarg($this->map->container['width'] . 'x' . $this->map->container['height']) . ' xc:black -set colorspace RGB');
		
		$im[]	= '-pointsize 12 -fill white -draw "text 10,20 ' . escapeshellarg(time()) . '"';
		
		$start	= array_shift($coordinates);
		
		$im[]	= ' -stroke white -strokeWidth 2 -fill none -draw "path ' . escapeshellarg('M ' . $start . ' L ' . implode(' ', $coordinates)) . '"'; // 
		
		$im[]	= escapeshellarg(__DIR__ . '/map.png');
		
		$command	= implode(" \\\n", $im);
		
		return $command;
	}
}

class AyMapApp
{
	private $map	= NULL;
	
	public function __construct(AyMap $map)
	{
		$this->map	= $map;
	}
	
	/**
	 * Removes any abnormal coordinates from the data set.
	 * Abnormal is defined - a distance between two points
	 * that cannot be reached without speeding.
	 */
	public function filter($data)
	{
		$remove	= array();
	
		for($i = 1, $j = count($data); $i < $j; $i++)
		{
			$distance	= $this->map->getOrthodromicDistance($data[$i-1][0], $data[$i-1][1], $data[$i][0], $data[$i][1])*1000; // meters
			$time		= $data[$i][2]-$data[$i-1][2]; // seconds
			
			// 1 (metre per second) = 2.23693629 miles per hour
			$speed		= ($distance/$time)*2.23693629;
			
			// London speed limit is 20km/h.
			// However, the car in the "cleaned.png" is speeding.
			if($speed > 40)
			{			
				$remove[]	= $i;
			}
		}
		
		foreach($remove as $i)
		{
			unset($data[$i]);
		}
		
		return array_values($data);
	}
}

class AyMapException extends Exception {}

if(!is_readable('journey.csv'))
{
	throw new Exception('Cannot read journey.csv.');
}

$csv	= file('journey.csv');

$data	= array_map('str_getcsv', $csv);

$map	= new AyMap(array('lat1' => 51.533122, 'lon1' => -0.172176, 'lat2' => 51.492633, 'lon2' => -0.106215), array('width' => 740, 'height' => 740));

$app	= new AyMapApp($map);

$data	= $app->filter($data);

if(PHP_SAPI == 'cli')
{
	$distance	= 0;

	for($i = 1, $j = count($data); $i < $j; $i++)
	{
		$distance	+= $map->getOrthodromicDistance($data[$i-1][0], $data[$i-1][1], $data[$i][0], $data[$i][1]);
	}
	
	$end		= end($data);
	
	$time		= $end[2]-$data[0][2];
	
	echo 'You made a total distance of ' . (floor(($distance*.621371192)*100)/100) . ' miles in ' . (floor(($time/60)*100)/100) . ' minutes with average speed of ' . (floor(($distance*1000/$time)*2.23693629*100)/100) . ' mph.' . PHP_EOL;
}
else
{
	$test	= new AyMapTest($map);
	
	$test->draw($data);
}