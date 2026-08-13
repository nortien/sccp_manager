<div class="container-fluid">
    <h1><?php echo $display_info?></h1>
    <div id="sccp-page-banner" class="alert alert-warning" style="display:none"></div>
    <div class="row">
        <div class="col-sm-12">
            <div class="fpbx-container">
                <div class="display no-border">
                    <div class="nav-container">
                        <div class="scroller scroller-left"><i class="glyphicon glyphicon-chevron-left"></i></div>
                        <div class="scroller scroller-right"><i class="glyphicon glyphicon-chevron-right"></i></div>
                        <div class="wrapper">
                            <ul class="nav nav-tabs list" role="tablist">
                                <?php foreach ($display_page as $key => $page) { ?>
                                    <li data-name="<?php echo $key?>" class="change-tab <?php echo $key == 'general' ? 'active' : ''?>"><a href="#<?php echo $key?>" aria-controls="<?php echo $key?>" role="tab" data-toggle="tab"><?php echo $page['name']?></a></li>
                                <?php } ?>
                            </ul>
                        </div>
                    </div>
                    <div class="tab-content display">
                        <?php foreach ($display_page as $key => $page) { ?>
                            <div id="<?php echo $key?>" class="tab-pane <?php echo $key == 'general' ? 'active' : ''?>" data-banner="<?php echo htmlspecialchars($page['banner'] ?? '', ENT_QUOTES); ?>">
                                <?php echo $page['content']?>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
// Move each tab's warning banner (if any) up above col-sm-12, next to the
// page title, matching FreePBX's own Advanced Settings page - rather than
// buried inside the tab content. Runs on load and on every tab switch since
// one page.html.php instance serves several tabs, each with its own banner.
(function () {
    function syncBanner() {
        var active = document.querySelector('.tab-pane.active');
        var banner = document.getElementById('sccp-page-banner');
        var text = active ? active.getAttribute('data-banner') : '';
        if (text) {
            banner.innerHTML = '<b><?php echo _("IMPORTANT:"); ?></b> ' + text;
            banner.style.display = '';
        } else {
            banner.style.display = 'none';
        }
    }
    document.addEventListener('DOMContentLoaded', syncBanner);
    $(document).on('shown.bs.tab', '.change-tab a', syncBanner);
    $(document).on('click', '.change-tab', function () { setTimeout(syncBanner, 0); });
})();
</script>
<!-- Modal alerts-->
<div class="modal" id="hwalert" tabindex="-1" role="dialog" aria-labelledby="lhwalert">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="myModalLabel">Modal title</h4>
      </div>
      <div class="modal-body">
        ...
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
