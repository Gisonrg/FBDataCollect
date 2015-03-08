<?php
session_start();

require('../vendor/autoload.php');

use Facebook\FacebookSession;
use Facebook\FacebookRedirectLoginHelper;
use Facebook\FacebookRequest;
use Facebook\GraphUser;
use Facebook\FacebookRequestException;
$app = new Silex\Application();

FacebookSession::setDefaultApplication('743548142431404','a5a20ef6df1b0d6f196e615d3e50fb48');


$app['debug'] = true;

// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
  'monolog.logfile' => 'php://stderr',
));

$app->register(new Silex\Provider\TwigServiceProvider(), array(
  'twig.path' => __DIR__.'/../views',
));

$helper = new FacebookRedirectLoginHelper('http://localhost:8888/fb-survey/web/fb');

// regester database
if (!($checkEnv = getenv('DATABASE_URL'))) {
	$dbsetting = "postgres://Gison@localhost:5432/fb_data";
	putenv("DATABASE_URL=".$dbsetting);
}

$dbopts = parse_url(getenv('DATABASE_URL'));

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

$app->get('/', function() use($app, $helper) {
  $app['monolog']->addDebug('logging output.');
  // $scope = array('user_status');

  // $loginURL = $helper->getLoginUrl($scope);
    $loginURL = $helper->getLoginUrl();
  return $app['twig']->render('index.twig', array(
        'loginURL' => $loginURL,
  ));
});

$app->get('/fb', function () use ($app, $helper) {
    try {
        $session = $helper->getSessionFromRedirect();
    } catch( FacebookRequestException $ex ) {
        // When Facebook returns an error
    } catch( Exception $ex ) {
        // When validation fails or other local issues
    }

    $graphArray = array();
    if ( isset( $session ) ) {
      // graph api request for user data
      $request = new FacebookRequest( $session, 'GET', '/me' );
      $response = $request->execute();
      // get response
      $graphObject = $response->getGraphObject();

      // print data
      // get array
      $graphArray = $graphObject->asArray();
    }

    return $app['twig']->render('result.twig', array(
        'name' => $graphArray['name'],
        'gender' => $graphArray['gender'],
        'page_link' => $graphArray['link']
    ));
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
