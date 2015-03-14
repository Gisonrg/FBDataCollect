var express = require('express');
var router = express.Router();
var fbgraph = require('fbgraphapi');

/* GET home page. */
// router.get('/', function(req, res) {
//   res.render('index', { title: 'Express' });
// });

router.get('/', function(req, res) {
    if (!req.hasOwnProperty('facebook')) {
        console.log('You are not logged in');
        return res.redirect('/login');
    }
    /* See http://developers.facebook.com/docs/reference/api/ for more */
    req.facebook.graph('/me', function(err, me) {
        console.log(me);
    });

    function getData(current) {
    	req.facebook.graph(current, function(err, result) {
    		if (result) {
    			for (var i=0;i<result.data.length;i++) {
		            console.log(result.data[i].message);
		            console.log(result.data[i].created_time);
		        }

		        if (result.paging && result.paging.next) {
		          current = result.paging.next;
		          if (current!=undefined) {
		            // can continue
		            getData(current);
		          }
		        } else {
		        	res.write("<p>Done!</p>");
		        	res.end();
		        }
    		} else {
		        	res.write("<p>Done!</p>");
		        	res.end();
		    }
	        
	   });
	}
	res.setHeader("Content-Type", "text/html");
	res.write("Please wait...");
	getData('/me/posts');
    
});

router.get('/login', function(req, res) {
    console.log('Start login');
    fbgraph.redirectLoginForm(req, res);    
});

module.exports = router;
