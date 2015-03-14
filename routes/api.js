var express = require('express');
var router = express.Router();
var graph = require('fbgraph');

/* GET users listing. */
router.get('/', function(req, res) {
  // set up facebook id
  // res.json({"status": 200});
  // graph.setAccessToken("CAACEdEose0cBAK1CQ05PmtIo5HRLnqrHQz2hbI91ZBHEn3jpXPdrXQpX3ePPo2KdHVFW6yLmFRuobl2cwu6jiNZBJIPOGnvmzd4aDDIhB3W8T1gvPzGC7prinzRxf3GH4LEgjfb3sxZCwYWpacYrW67qZAfVZAPL2wZAJjO2p9hVaSjOIoB3GTwGyOoUdsWAxYv8ItD3pOz4U4eeuTWJmQ");
  console.log(req.body.token);
});

router.get('/fbtest', function(request, response) {
	graph.setAccessToken("CAACEdEose0cBAPawMf23BTlecEsbLcdJKaRTtQSXO8nWUb77WWvcKWjX0sUICvDZCv1pwgqgLGZAAoy5ZAmsMLZA5PKLVV9KXZCZCaZA9SwdDnzX1QMI0g5FMErfDl8JnhgjraiPenDCyeokZAShOaGXy8dUXoUmBip3suQ8g85VvMEt8bd1fAOMZBBMW0W1GPhqTWkGzAZAftLUSflF2kCgTE");

	function getData(current) {
	    graph.get(current, function(err, res) {
	        for (var i=0;i<res.data.length;i++) {
	            console.log(res.data[i].message);
	            console.log(res.data[i].created_time);
	        }

	        if (res.paging && res.paging.next) {
	          current = res.paging.next;
	          if (current!=undefined) {
	            // can continue
	            getData(current);
	          }
	        } else {
	        	response.write("<p>Done!</p>");
	        	response.end();
	        }
	    });
	}
  var result = ''
  var times = process.env.TIMES || 5
  for (i=0; i < times; i++)

  // graph.get('/me/posts', function(err, res) {
  //   for (var i=0;i<res.data.length;i++) {
  //       console.log(res.data[i].message);
  //       console.log(res.data[i].created_time);
  //   }
  //   var current = res.paging.next;
  //   getData(current);
  // });
	response.setHeader("Content-Type", "text/html");
    response.write("<p>Running...</p>");
	getData('/me/posts');

	
});

module.exports = router;
