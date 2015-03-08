<?php

require('../vendor/autoload.php');

$app = new Silex\Application();
$app['debug'] = true;

// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
  'monolog.logfile' => 'php://stderr',
));

$app->register(new Silex\Provider\TwigServiceProvider(), array(
  'twig.path' => __DIR__.'/../views',
));

// regester database
if (!($checkEnv = getenv('DATABASE_URL'))) {
	$dbsetting = "postgres://Gison@localhost:5432/fb_data";
	putenv("DATABASE_URL=".$dbsetting);
	echo getenv('DATABASE_URL');
}

$dbopts = parse_url(getenv('DATABASE_URL'));

print_r($dbopts);

if (!isset($dbopts["pass"])) {
	$dbopts["pass"] = '';
}

$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => array(
    	'host' => $dbopts["host"],
        'driver'   => 'pdo_pgsql',
        'dbname'   => ltrim($dbopts["path"],'/'),
        'port'     => $dbopts["port"],
        'user'    => $dbopts["user"],
        'password'  => $dbopts["pass"]
    ),
));

// Our web handlers

$app->get('/', function() use($app) {
  $app['monolog']->addDebug('logging output.');
  return 'Hello';
});

$app->get('/hello/{name}', function ($name) use ($app) {
    return 'Hello '.$app->escape($name);
});

$app->get('/twig/{name}', function ($name) use ($app) {
    return $app['twig']->render('index.twig', array(
        'name' => $name,
    ));
});

$app->get('/db/', function() use($app) {
	$sql = "SELECT name FROM test_table";
	$post = array();
	$stmt = $app['db']->query($sql);
	while ($row = $stmt->fetch()) {
    	$post[] = $row;
	}
	return $app['twig']->render('database.twig', array(
	   'names' => $post
	));
});

$app->run();

?>
