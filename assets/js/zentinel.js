window.ZentinelFilter = {
    activeHour: null,

    filterByHour: function (hour, element) {
        if (this.activeHour === hour) {
            this.reset();
            return;
        }
        this.activeHour = hour;

        // Visual Barras
        document.querySelectorAll(".trend-bar").forEach(el => el.classList.remove("active"));
        const bar = element.querySelector(".trend-bar");
        if (bar) bar.classList.add("active");

        // Filtro Cards
        const cards = document.querySelectorAll(".kanban-card");
        let visibleCount = 0;
        cards.forEach(card => {
            if (card.getAttribute("data-hour") === hour) {
                card.classList.remove("hidden");
                visibleCount++;
            } else {
                card.classList.add("hidden");
            }
        });

        // Botão Reset
        const btn = document.getElementById("js-reset-btn");
        if (btn) {
            btn.style.display = "block";
            btn.innerText = "Filtering: " + hour + " (" + visibleCount + ") ×";
        }
    },

    reset: function () {
        this.activeHour = null;
        document.querySelectorAll(".trend-bar").forEach(el => el.classList.remove("active"));
        document.querySelectorAll(".kanban-card").forEach(el => el.classList.remove("hidden"));
        const btn = document.getElementById("js-reset-btn");
        if (btn) btn.style.display = "none";
    }
};