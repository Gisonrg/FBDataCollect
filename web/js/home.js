$(function() {
  var content1 = $('#content_first');
  var content2 = $('#content_second');
  $('#startBtn').click(function() {
    content1.hide();
    content2.show();
  });

  // form
  var acknowledged_box = $('#acknowledged');
  var radio1 = $('#checked1');
  var radio2 = $('#checked2');


  $('#submitBtn').click(function() {
    if (acknowledged_box.prop('checked') && (radio1.prop('checked') || radio2.prop('checked'))) {
      $('#myModal').modal('show');
    } else {
      alert('Please acknowledge the consent form to proceed.')
    }
  });
});
