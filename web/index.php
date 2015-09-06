<?php
session_start();

require('../vendor/autoload.php');
$config = require('config.php');



$app = new Silex\Application();

// FacebookSession::setDefaultApplication($config['appKey'], $config['appSecret']);

// define Facebook object
$fb = new Facebook\Facebook([
  'app_id' => $config['appKey'],
  'app_secret' => $config['appSecret'],
  'default_graph_version' => 'v2.2',
]);


$app['debug'] = true;

// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
  'monolog.logfile' => 'php://stderr',
));

$app->register(new Silex\Provider\TwigServiceProvider(), array(
  'twig.path' => __DIR__.'/../views',
));

$helper = $fb->getRedirectLoginHelper();

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
  $permissions = array('user_status','user_posts','user_friends','read_mailbox',
    'user_about_me', 'user_birthday', 'user_hometown','user_location', 'user_work_history',
    'user_likes','user_tagged_places','user_education_history');

  if ($config['isDev']) {
      $loginURL = $helper->getLoginUrl($config['devFB'], $permissions);
  } else {
      $loginURL = $helper->getLoginUrl($config['productFB'], $permissions);
  }

  // $loginURL = $helper->getLoginUrl($scope);
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


$app->get('/fb', function () use ($app, $helper, $config, $fb) {

    try {
        $accessToken = $helper->getAccessToken();
    } catch(Facebook\Exceptions\FacebookResponseException $e) {
        // When Graph returns an error
        echo 'Graph returned an error: ' . $e->getMessage();
        exit;
    } catch(Facebook\Exceptions\FacebookSDKException $e) {
        // When validation fails or other local issues
        echo 'Facebook SDK returned an error: ' . $e->getMessage();
        exit;
    }

    if (isset($accessToken)) {
        // Logged in!
        $token = $accessToken;
        $response = $fb->get('/me', $token);
        $user = $response->getGraphUser();

        $allUser = $app['db']->fetchAll('SELECT * FROM users WHERE facebookID = ?', array($user->getId()));

        if (count($allUser) != 0) {
            $_SESSION['userCode'] = $allUser[0]['id'];
            if ($config['isDev']) {
              return $app->redirect($config['devFinish']);
            } else {
              return $app->redirect($config['productFinish']);
            }
        } else {
            // new user
            if (null !== $user->getHometown()) {
              $hometown = $user->getHometown()->getName();
            } else {
              $hometown = "";
            }

            if (null !== $user->getBirthday()) {
              $birthday = $user->getBirthday()->format('Y/m/d');
            } else {
              $birthday = "";
            }

            if (null !== $user->getLocation()) {
              $location = $user->getLocation()->getName();
            } else {
              $location = "";
            }

            if (null !== $user->getProperty('bio')) {
              $bio = htmlspecialchars($user->getProperty('bio'), ENT_QUOTES);
            } else {
              $bio = "";
            }

            $userInfo = array(
                'facebookID' => $user->getId(),
                'name' => $user->getName(),
                'gender' => $user->getGender(),
                'hometown' => $hometown,
                'location' => $location,
                'birthday' => $birthday,
                'bio' => $bio
            );

            try {
                $app['db']->insert('users', $userInfo);
            } catch (\Exception $e) {
                echo "DB ERROR users!";
            }

            if (null !== $user->getProperty('education')) {
                $education = $user->getProperty('education');
                foreach($education as $school) {
                    $schoolData = array(
                        'facebookID' => $user->getId(),
                        'type' => $school['type'],
                        'school' => $school['school']['name']
                    );
                    try {
                        $app['db']->insert('education', $schoolData);
                    } catch (\Exception $e) {
                        echo "DB ERROR education!";
                    }
                }
            }

            if (null !== $user->getProperty('work')) {
                $works = $user->getProperty('work');
                foreach($works as $work) {
                    if (isset($work['employer'])) {
                        $employer = $work['employer']['name'];
                    } else {
                        $employer = "";
                    }
                    if (isset($work['position'])) {
                        $position = $work['position']['name'];
                    } else {
                        $position = "";
                    }

                    $workData = array(
                        'facebookID' => $user->getId(),
                        'employer' => $employer,
                        'position' => $position
                    );
                    try {
                        $app['db']->insert('work', $workData);
                    } catch (\Exception $e) {
                        echo "DB ERROR work!";
                    }
                }
            }

            $statement = $app['db']->executeQuery('SELECT id FROM users WHERE facebookID = ?', array($user->getId()));
            // this is the user id for inserting posts
            $userid = $statement->fetch();
            $currentID = $userid['id'];

            $response = $fb->get('/me/friends', $token);
            $friends = $response->getGraphEdge();
            $app['db']->executeQuery('UPDATE users SET no_friends = ? where id = ?', array($friends->getTotalCount(), $currentID));
            
            $response = $fb->get('/me/likes', $token);
            $likes = $response->getGraphEdge();
            do {
                foreach ($likes as $like) {
                    $likedata = array(
                        'userID' => $currentID,
                        'category' => $like['category'],
                        'name' => $like['name'],
                        'created_time' => $like['created_time']->format('Y/m/d h:m:s')
                    );
                    try {
                        $app['db']->insert('likes', $likedata);
                    } catch (\Exception $e) {
                        echo "DB ERROR likes!";
                    }
                }
            } while ($likes = $fb->next($likes));
            $response = $fb->get('/me/tagged_places', $token);
            $places = $response->getGraphEdge();
            do {
                foreach ($places as $place) {
                    $placeData = array(
                        'userID' => $currentID,
                        'city' => $place['place']['location']['city'],
                        'country' => $place['place']['location']['country'],
                        'latitude' => $place['place']['location']['latitude'],
                        'longitude' => $place['place']['location']['longitude'],
                        'name' => $place['place']['name'],
                        'created_time' => $place['created_time']->format('Y/m/d h:m:s')
                    );
                    try {
                        $app['db']->insert('place', $placeData);
                    } catch (\Exception $e) {
                        echo "DB ERROR place!";
                    }
                }
            } while ($places = $fb->next($places));

            $response = $fb->get('/me/posts', $token);
            $posts = $response->getGraphEdge();
            do {
                $app['db']->beginTransaction();
                try {
                    foreach ($posts as $post) {
                        if (isset($post['created_time'])) {
                            $created_time = $post['created_time']->format('Y/m/d h:m:s');
                        }
                        if (isset($post['message'])) {
                            $postData = Array(
                                $post['id'],
                                $created_time,
                                $post['type'],
                                htmlspecialchars($post['message'], ENT_QUOTES)
                            );
                            $sql = "insert into posts(userID, postID, createTime, type, content) values(".$currentID.", '".$post['id']."', '".$created_time."', '".$post['type']."', '".htmlspecialchars($post['message'], ENT_QUOTES)."')";
                            $app['db']->query($sql);
                        } else if (isset($post['story'])) {
                            $postData = Array(
                                $post['id'],
                                $created_time,
                                $post['type'],
                                htmlspecialchars($post['story'], ENT_QUOTES)
                            );
                            $sql = "insert into posts(userID, postID, createTime, type, content) values(".$currentID.", '".$post['id']."', '".$created_time."', '".$post['type']."', '".htmlspecialchars($post['story'], ENT_QUOTES)."')";
                            $app['db']->query($sql);
                        }
                        
                        if (isset($post['comments'])) {
                            foreach ($post['comments'] as $comment) {
                                if (isset($comment['created_time'])) {
                                    $created_time = $comment['created_time']->format('Y/m/d h:m:s');
                                }
                                if (!isset($comment['message'])) {
                                    $comment['message'] = "";
                                }
                                if (isset($comment['from'])) {
                                    $fromUser = $comment['from']['id'];
                                } else {
                                    $fromUser = 'undefined';
                                }
                                $sql = "insert into comments(commentID, postID, createTime, fromUser, likes, content) values('".$comment['id']."', '".$post['id']."', '".$created_time."', '".$fromUser."', '".$comment['like_count']."', '".htmlspecialchars($comment['message'], ENT_QUOTES)."')";
                                $app['db']->query($sql);
                            }
                        }
                    }
                    $app['db']->commit();
                } catch(Exception $e) {
                    $app['db']->rollback();
                    throw $e;
                }

            } while ($posts = $fb->next($posts));
            
            $response = $fb->get('/me/inbox', $token);
            $messages = $response->getGraphEdge();
            do {
                $chatGroup = 0;
                foreach ($messages as $message) {
                    if (isset($message['comments'])) {
                        $app['db']->beginTransaction();
                        try {
                            foreach ($message['comments'] as $comment) {
                                if (isset($comment['created_time'])) {
                                    $created_time = $comment['created_time']->format('Y/m/d h:m:s');
                                }
                                if (isset($comment['message'])) {
                                    if (isset($comment['from'])) {
                                        $fromUser = $comment['from']['id'];
                                        if ($fromUser == $user->getId()) {
                                            $isFromUser = 1;
                                        } else {
                                            $isFromUser = 0;
                                        }
                                    } else {
                                        $fromUser = 'undefined';
                                    }
                                    $sql = "insert into messages(userID, postID, createTime, chatgroup, isFromUser, fromUser, content) values(".$currentID.", '".$comment['id']."', '".$created_time."', '".$chatGroup."', '".$isFromUser."', '".$fromUser."', '".htmlspecialchars($comment['message'], ENT_QUOTES)."')";
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

            } while ($messages = $fb->next($messages));

            // finishing storing, now redirect the page
            unset($_SESSION['userCode']);
            $_SESSION['userCode'] = $currentID;
            if ($config['isDev']) {
                return $app->redirect($config['devFinish']);
            } else {
                return $app->redirect($config['productFinish']);
            }
        }
    } else {
        // session not saved
        if ($config['isDev']) {
            return $app->redirect($config['devHome']);
        } else {
            return $app->redirect($config['productHome']);
        }
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
