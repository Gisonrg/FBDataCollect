<?php
session_start();

require('../vendor/autoload.php');
$config = require('config.php');

use Facebook\FacebookSession;
use Facebook\FacebookRedirectLoginHelper;
use Facebook\FacebookRequest;
use Facebook\GraphUser;
use Facebook\FacebookRequestException;

$app = new Silex\Application();

FacebookSession::setDefaultApplication($config['appKey'], $config['appSecret']);


$app['debug'] = true;

// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
  'monolog.logfile' => 'php://stderr',
));

$app->register(new Silex\Provider\TwigServiceProvider(), array(
  'twig.path' => __DIR__.'/../views',
));

if ($config['isDev']) {
    $helper = new FacebookRedirectLoginHelper($config['devFB']);
} else {
    $helper = new FacebookRedirectLoginHelper($config['productFB']);
}
// $helper = new FacebookRedirectLoginHelper('http://localhost:8888/fb-survey/web/fb');

// regester database
if (!($checkEnv = getenv('DATABASE_URL'))) {
	$dbsetting = $config['devDB'];
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

$app->get('/', function() use($app, $helper, $config) {
  $app['monolog']->addDebug('logging output.');
  $scope = array('user_status','user_posts','user_friends','read_mailbox',
    'user_about_me', 'user_birthday', 'user_hometown','user_location', 'user_work_history',
    'user_likes','user_tagged_places','user_education_history');

  $loginURL = $helper->getLoginUrl($scope);
  $showAlert = false;
  if (isset($_SESSION['doneBefore'])) {
    $showAlert = true;
  }
  unset($_SESSION['doneBefore']);

  return $app['twig']->render('index.twig', array(
        'loginURL' => $loginURL,
        'isShowAlert' => $showAlert
  ));
});

$session = null;

$app->get('/fb', function () use ($app, $helper, $config) {
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
            // user has done before, we start to get their messagers data
            $_SESSION['userCode'] = $allUser[0]['id'];
            $currentID = $allUser[0]['id'];
            $currentFBID = $graphArray['id'];

            // get inbox message
            $request = new FacebookRequest( $session, 'GET', '/me/inbox');
            $response = $request->execute();
            // get response
            $graphObject = $response->getGraphObject();
            $inbox = $graphObject->asArray();
            $chatGroup = 0;
            $isFromUser = 0;
            foreach($inbox['data'] as $message) {
                if (isset($message->comments)) {
                    $app['db']->beginTransaction();
                    try {
                        foreach($message->comments->data as $comment) {
                            if (isset($comment->message)) {
                                if (isset($comment->from)) {
                                    $fromUser = $comment->from->id;
                                    if ($fromUser == $currentFBID) {
                                        $isFromUser = 1;
                                    } else {
                                        $isFromUser = 0;
                                    }
                                } else {
                                    $fromUser = 'undefined';
                                }
                                
                                $sql = "insert into messages(userID, postID, createTime, chatgroup, isFromUser, fromUser, content) values(".$currentID.", '".$comment->id."', '".$comment->created_time."', '".$chatGroup."', '".$isFromUser."', '".$fromUser."', '".htmlspecialchars($comment->message, ENT_QUOTES)."')";
                                // echo $comment->message." at time ".$comment->created_time;
                                $app['db']->query($sql);
                            }
                        }
                        $app['db']->commit();
                    } catch(Exception $e) {
                        $app['db']->rollback();
                        throw $e;
                    }
                    $chatGroup++;
                }
            }
            if ($config['isDev']) {
                return $app->redirect($config['devFinish']);
            } else {
                return $app->redirect($config['productFinish']);
            }
        } else {
            // insert the users
            if (isset($graphArray['hometown'])) {
                $hometown = $graphArray['hometown']->name;
            } else {
                $hometown = "";
            }

            if (isset($graphArray['birthday'])) {
                $birthday = $graphArray['birthday'];
            } else {
                $birthday = "";
            }

            if (isset($graphArray['location'])) {
                $location = $graphArray['location'] -> name;
            } else {
                $location = "";
            }

            if (isset($graphArray['bio'])) {
                $bio = htmlspecialchars($graphArray['bio'], ENT_QUOTES);
            } else {
                $bio = "";
            }

            try {
                $app['db']->insert('users', array(
                                            'facebookID' => $graphArray['id'],
                                            'name' => $graphArray['name'],
                                            'gender' => $graphArray['gender'],
                                            'hometown' => $hometown,
                                            'location' => $location,
                                            'birthday' => $birthday,
                                            'bio' => $bio
                                            )
                );
            } catch (\Exception $e) {
                echo "DB ERROR!";
            }
            if (isset($graphArray['education'])) {
                foreach($graphArray['education'] as $school) {
                    try {
                        $app['db']->insert('education', array(
                                                    'facebookID' => $graphArray['id'],
                                                    'type' => $school->type,
                                                    'school' => $school->school->name
                                                    )
                        );
                    } catch (\Exception $e) {
                        echo "DB ERROR!";
                    }
                }
            }

            if (isset($graphArray['work'])) {
                foreach($graphArray['work'] as $work) {
                    if (isset($work->employer)) {
                        $employer = $work->employer->name;
                    } else {
                        $employer = "";
                    }
                    if (isset($work->position)) {
                        $position = $work->position->name;
                    } else {
                        $position = "";
                    }
                    try {
                        $app['db']->insert('work', array(
                                                    'facebookID' => $graphArray['id'],
                                                    'employer' => $employer,
                                                    'position' => $position
                                                    )
                        );
                    } catch (\Exception $e) {
                        echo "DB ERROR!";
                    }
                }
            }
        }

        $statement = $app['db']->executeQuery('SELECT id FROM users WHERE facebookID = ?', array($graphArray['id']));
        // this is the user id for inserting posts
        $userid = $statement->fetch();
        $currentID = $userid['id'];
        // facebook ID
        $currentFBID = $graphArray['id'];
        // get friends
        $request = new FacebookRequest( $session, 'GET', '/me/friends');
        $response = $request->execute();
        // get response
        $graphObject = $response->getGraphObject();
        $friendsArray = $graphObject->asArray();
        print_r($friendsArray['summary']);
        // update friends number
        $app['db']->executeQuery('UPDATE users SET no_friends = ? where id = ?', array($friendsArray['summary']->total_count, $currentID));

        // get inbox message
        $request = new FacebookRequest( $session, 'GET', '/me/inbox');
        $response = $request->execute();
        // get response
        $graphObject = $response->getGraphObject();
        $inbox = $graphObject->asArray();
        $chatGroup = 0;
        $isFromUser = false;
        foreach($inbox['data'] as $message) {
            if (isset($message->comments)) {
                $app['db']->beginTransaction();
                try {
                    foreach($message->comments->data as $comment) {
                        if (isset($comment->message)) {
                            if (isset($comment->from)) {
                                $fromUser = $comment->from->id;
                                if ($fromUser == $currentFBID) {
                                    $isFromUser = 1;
                                } else {
                                    $isFromUser = 0;
                                }
                            } else {
                                $fromUser = 'undefined';
                            }
                            
                            $sql = "insert into messages(userID, postID, createTime, chatgroup, isFromUser, fromUser, content) values(".$currentID.", '".$comment->id."', '".$comment->created_time."', '".$chatGroup."', '".$isFromUser."', '".$fromUser."', '".htmlspecialchars($comment->message, ENT_QUOTES)."')";
                            // echo $comment->message." at time ".$comment->created_time;
                            $app['db']->query($sql);
                        }
                    }
                    $app['db']->commit();
                } catch(Exception $e) {
                    $app['db']->rollback();
                    throw $e;
                }
                $chatGroup++;
            }
        }
        // finishing storing, now redirect the page
        unset($_SESSION['userCode']);
        $_SESSION['userCode'] = $currentID;
    } else {
        if ($config['isDev']) {
            return $app->redirect($config['devHome']);
        } else {
            return $app->redirect($config['productHome']);
        }
    }

    if ($config['isDev']) {
        return $app->redirect($config['devFinish']);
    } else {
        return $app->redirect($config['productFinish']);
    }
});

$app->get('/finish', function() use($app, $config) {
    if (!isset($_SESSION['userCode'])) {
        if ($config['isDev']) {
            return $app->redirect($config['devHome']);
        } else {
            return $app->redirect($config['productHome']);
        }
    }
    $displayCode = $_SESSION['userCode'];
    unset($_SESSION['userCode']); // only shown code once!
    return $app['twig']->render('finish.twig', array(
        'code' => $displayCode
    ));
});

$app->run();

?>
