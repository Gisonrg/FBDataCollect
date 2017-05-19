<?

$config = array();

$config['appKey'] = '743548142431404';
$config['appSecret'] = 'a5a20ef6df1b0d6f196e615d3e50fb48';

$config['isDev'] = false;
$config['devDB'] = "postgres://Gison@localhost:5432/fb_data";

$config['productHome'] = "https://socialmedia-survey.herokuapp.com/";
$config['productFB'] = "https://socialmedia-survey.herokuapp.com/fb";
$config['productFinish'] = "https://socialmedia-survey.herokuapp.com/finish";

$config['devHome'] = "http://localhost:8888/fb-survey/web/";
$config['devFB'] = "http://localhost:8888/fb-survey/web/fb";
$config['devFinish'] = "http://localhost:8888/fb-survey/web/finish";

return $config;

?>
