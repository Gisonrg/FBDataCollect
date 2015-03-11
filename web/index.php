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

$helper = new FacebookRedirectLoginHelper('https://socialmedia-survey.herokuapp.com/fb');

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
  $scope = array('user_status','user_posts');

  $loginURL = $helper->getLoginUrl($scope);

  if (isset($_SESSION['doneBefore'])) {
    echo "You have done this survey before!";
  }
  unset($_SESSION['doneBefore']);
  return $app['twig']->render('index.twig', array(
        'loginURL' => $loginURL,
  ));
});

$app->get('/fb', function () use ($app, $helper) {
    try {
        $session = $helper->getSessionFromRedirect();
    } catch( FacebookRequestException $ex ) {
        $app['monolog']->addDebug('facebook error.');
        // When Facebook returns an error
    } catch( Exception $ex ) {
        // When validation fails or other local issues
        $app['monolog']->addDebug('exception bug.');
    }

    $graphArray = array();
    $statusArray = array();
    if (isset( $session ) ) {
        $app['monolog']->addDebug('enter session.');
        // graph api request for user data
        $request = new FacebookRequest( $session, 'GET', '/me' );
        $response = $request->execute();
        // get response
        $graphObject = $response->getGraphObject();
        $graphArray = $graphObject->asArray();

        // 1. check if user already exists
        $allUser = $app['db']->fetchAll('SELECT * FROM users WHERE facebookID = ?', array($graphArray['id']));

        if (count($allUser) != 0) {
            $_SESSION['doneBefore'] = "YES";
            return $app->redirect('https://socialmedia-survey.herokuapp.com/');
        } else {
            // insert the users
            try {
                $app['db']->insert('users', array(
                                            'facebookID' => $graphArray['id'],
                                            'name' => $graphArray['name'],
                                            'gender' => $graphArray['gender'],
                                            'locale' => $graphArray['locale']
                                            )
                );
            } catch (\Exception $e) {
                echo "DB ERROR!";
            }
        }

        // 1. check if user already exists
        $statement = $app['db']->executeQuery('SELECT id FROM users WHERE facebookID = ?', array($graphArray['id']));
        // this is the user id for inserting posts
        $userid = $statement->fetch();
        $currentID = $userid['id'];

        // get array
        $request = new FacebookRequest( $session, 'GET', '/me/posts');
        $response = $request->execute();

        do {
            $response = $request->execute();
            // get response
            $graphObject = $response->getGraphObject();
            $statusArray = $graphObject->asArray();
            // print data
            // get array
            if (isset($statusArray['data'])) {
                $app['db']->beginTransaction();
                try {
                    foreach($statusArray['data'] as $post) { 
                        // echo "Post id: ".$post->id."<br>";
                        if (isset($post->message)) {
                          // echo "Message: ".$post->message."<br>"; 
                        } else {
                            $post->message = "";
                        }
                        // echo "Type: ".$post->type."<br>";
                        // echo "Created Time".$post->created_time."<br>";
                        // echo "<br>";
                        $sql = "insert into posts(userid, createtime, type, content) values(".$currentID.", '".$post->created_time."', '".$post->type."', '".htmlspecialchars($post->message, ENT_QUOTES)."')";
                        $app['db']->query($sql);
                    } 
                    $app['db']->commit();
                } catch(Exception $e) {
                    $app['db']->rollback();
                    throw $e;
                }
            }
        } while ($request = $response->getRequestForNextPage());
        // finishing storing, now redirect the page
        unset($_SESSION['userCode']);
        $_SESSION['userCode'] = $currentID;
    }
    return $app->redirect('https://socialmedia-survey.herokuapp.com/finish');
    // return $app['twig']->render('result.twig', array(
    //     'name' => $graphArray['name'],
    //     'gender' => $graphArray['gender'],
    //     'page_link' => $graphArray['link']
    //     ));
});

$app->get('/finish', function() use($app) {
    if (!isset($_SESSION['userCode'])) {
        $_SESSION['userCode'] = "Invalid Visit";
    }
    $displayCode = $_SESSION['userCode'];
    unset($_SESSION['userCode']); // only shown code once!
    return $app['twig']->render('finish.twig', array(
        'code' => $displayCode
    ));
});

function addUser($id, $name, $gender, $locale) {
    $sql = "insert into users(facebookID, name, gender, locale) values ('".$id."', 
                '".$name."', '".$gender."', '".$locale."')";
    $post = array();
    $stmt = $app['db']->query($sql);
    while ($row = $stmt->fetch()) {
        $post[] = $row;
    }
}

$app->run();

?>
