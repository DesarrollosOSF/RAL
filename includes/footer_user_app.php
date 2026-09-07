        </div>
    </div>
</div>
<div class="user-sidebar-backdrop d-lg-none" id="user-sidebar-backdrop" hidden aria-hidden="true"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  var body = document.body;
  var toggle = document.getElementById('user-menu-toggle');
  var backdrop = document.getElementById('user-sidebar-backdrop');
  function setOpen(open) {
    body.classList.toggle('user-sidebar-open', open);
    if (backdrop) {
      backdrop.hidden = !open;
      backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
    }
    if (toggle) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
  }
  if (toggle) {
    toggle.addEventListener('click', function () {
      setOpen(!body.classList.contains('user-sidebar-open'));
    });
  }
  if (backdrop) {
    backdrop.addEventListener('click', function () { setOpen(false); });
  }
  window.addEventListener('resize', function () {
    if (window.matchMedia('(min-width: 992px)').matches) {
      setOpen(false);
    }
  });
})();
</script>
</body>
</html>
