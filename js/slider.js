
    const slides = document.querySelectorAll('.slide');
    const dots = document.querySelectorAll('.dot');
    const prev = document.querySelector('.prev');
    const next = document.querySelector('.next');

    let index = 0;
    let timer = setInterval(autoSlide, 4000);

    function showSlide(n) {
      slides.forEach((slide, i) => {
        slide.classList.toggle('active', i === n);
        dots[i].classList.toggle('active', i === n);
      });
      index = n;
    }

    function autoSlide() {
      index = (index + 1) % slides.length;
      showSlide(index);
    }

    function goToSlide(n) {
      clearInterval(timer);
      showSlide(n);
      timer = setInterval(autoSlide, 4000);
    }

    prev.addEventListener('click', () => {
      goToSlide((index - 1 + slides.length) % slides.length);
    });

    next.addEventListener('click', () => {
      goToSlide((index + 1) % slides.length);
    });

    dots.forEach((dot, i) => {
      dot.addEventListener('click', () => goToSlide(i));
    });
