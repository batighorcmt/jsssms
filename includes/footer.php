<?php
// includes/footer.php
?>
  <!-- Main Footer -->
  <footer class="main-footer text-sm">
    <div class="float-right d-none d-sm-inline">
      JSSSMS
    </div>
    <strong>&copy; <?php echo date('Y'); ?> Jorepukuria Secondary School, Gangni, Meherpur.</strong> All rights reserved.
  </footer>

  <!-- REQUIRED SCRIPTS: jQuery, Bootstrap 4, AdminLTE -->
  <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
  <!-- Bootstrap Datepicker JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/js/bootstrap-datepicker.min.js"></script>

  <script>
  (function(){
    function isISO(v){ return /^\d{4}-\d{2}-\d{2}$/.test(v || ''); }
    function toISO(v){
      if (!v) return v;
      v = String(v).trim();
      if (isISO(v)) return v;
      var m = v.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
      if (m){
        var d = m[1].padStart(2,'0');
        var mo = m[2].padStart(2,'0');
        var y = m[3];
        return y + '-' + mo + '-' + d;
      }
      return v;
    }
    function toDMY(v){
      if (!v) return v;
      v = String(v).trim();
      if (isISO(v)){
        var p = v.split('-');
        return [p[2], p[1], p[0]].join('/');
      }
      var m = v.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
      if (m){
        return m[1].padStart(2,'0') + '/' + m[2].padStart(2,'0') + '/' + m[3];
      }
      return v;
    }
    function initDatepicker(el){
      // Convert existing ISO value to display format
      if (el.value) el.value = toDMY(el.value);
      $(el).datepicker({
        format: 'dd/mm/yyyy',
        autoclose: true,
        todayHighlight: true,
        orientation: 'bottom'
      }).on('changeDate', function(e){
        // ensure input shows selected date in dd/mm/yyyy
        el.value = e.format(0,'dd/mm/yyyy');
      }).on('clearDate', function(){ el.value=''; });
      el.setAttribute('placeholder','dd/mm/yyyy');
    }
    // Expose a global initializer for dynamically added date inputs
    window.setupDateInputs = function(root){
      var scope = root || document;
      var els = scope.querySelectorAll('input.date-input');
      els.forEach(function(el){
        // Avoid double-initialization
        if (!$(el).data('datepicker')) initDatepicker(el);
      });
    };

    function init(){
      window.setupDateInputs(document);
      document.querySelectorAll('form').forEach(function(form){
        form.addEventListener('submit', function(){
          form.querySelectorAll('input.date-input').forEach(function(el){ el.value = toISO(el.value); });
        });
      });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  })();
  </script>

  <!-- Global Toast Container -->
  <div aria-live="polite" aria-atomic="true" style="position: fixed; top:1rem; right:1rem; z-index:1080;">
    <div id="globalToast" class="toast" role="alert" data-delay="5000" data-autohide="true">
      <div class="toast-header">
        <strong class="mr-auto" id="toastTitle">Status</strong>
        <small class="text-muted">Just now</small>
        <button type="button" class="ml-2 mb-1 close" data-dismiss="toast" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="toast-body" id="toastBody"></div>
    </div>
  </div>

  <script>
    // Global toast helper
    window.showToast = function(title, body, type){
      var t = $('#globalToast');
      $('#toastTitle').text(title||'Message');
      $('#toastBody').html(body||'');
      t.removeClass('bg-success bg-danger text-white');
      if(type==='success'){ t.addClass('bg-success text-white'); }
      else if(type==='error'){ t.addClass('bg-danger text-white'); }
      t.toast('show');
    };
  </script>

</div><!-- /.wrapper -->
</body>
</html>
