document.addEventListener("DOMContentLoaded", () => {

    const loader = document.getElementById("loader-wrapper");
    const bar = loader?.querySelector(".loader");

    if (!loader || !bar) return;

    function startLoader() {

        loader.classList.remove("hide");

        bar.style.transition = "none";
        bar.style.width = "0%";

        requestAnimationFrame(() => {

            bar.style.transition = "width .35s ease";

            setTimeout(() => bar.style.width = "30%", 20);
            setTimeout(() => bar.style.width = "55%", 120);
            setTimeout(() => bar.style.width = "75%", 300);
            setTimeout(() => bar.style.width = "90%", 700);

        });

    }

    function stopLoader() {

        bar.style.width = "100%";

        setTimeout(() => {
            loader.classList.add("hide");

            setTimeout(() => {
                bar.style.transition = "none";
                bar.style.width = "0%";
            }, 300);

        }, 100);

    }

    window.addEventListener("load", stopLoader);

    document.querySelectorAll("a").forEach(link => {

        if (
            !link.href ||
            link.href.startsWith("javascript:") ||
            link.href.includes("#") ||
            link.target === "_blank"
        ) return;

        link.addEventListener("click", e => {

            if (e.ctrlKey || e.metaKey || e.shiftKey) return;

            startLoader();

        });

    });

    document.querySelectorAll("form").forEach(form => {

        form.addEventListener("submit", startLoader);

    });

    window.addEventListener("pageshow", () => {

        stopLoader();

    });

});