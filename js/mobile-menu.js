document.addEventListener('DOMContentLoaded', function () {
  const toggleBtn = document.querySelector('.menu-toggle');
  const menu = document.querySelector('.menu');
  const dropdownButtons = document.querySelectorAll('.dropbtn');

  if (!toggleBtn || !menu) return; // Safety check

  // Toggle the menu open/close
  toggleBtn.addEventListener('click', function () {
    menu.classList.toggle('active');
    toggleBtn.textContent = menu.classList.contains('active') ? '✕' : '☰';
  });

  // Dropdown toggle for mobile only
  dropdownButtons.forEach(btn => {
    btn.addEventListener('click', function (e) {
      if (window.innerWidth <= 820) {
        e.preventDefault();
        btn.parentElement.classList.toggle('open');
      }
    });
  });
});
