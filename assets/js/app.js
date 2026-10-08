/* ============================================
   SISTEMA DE PRÉSTAMOS - JavaScript
   ============================================ */

function formatMoney(amount, currency) {
    currency = currency || 'MXN';
    const n = parseFloat(amount) || 0;
    const parts = n.toFixed(2).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return currency + ' ' + parts.join(',');
}

const TIPO_PLAZO = {
    dias:    { factorTasa: 1/30, multiplicadorPlazo: 1, unidad: 'días',   unidadCorta: 'd', divisorFecha: 1 },
    semanas: { factorTasa: 7/30, multiplicadorPlazo: 1, unidad: 'semanas', unidadCorta: 'sem', divisorFecha: 7 },
    meses:   { factorTasa: 1,    multiplicadorPlazo: 1, unidad: 'meses',  unidadCorta: 'mes', divisorFecha: 30 },
    anios:   { factorTasa: 12,   multiplicadorPlazo: 12, unidad: 'años',  unidadCorta: 'año', divisorFecha: 365 }
};

function calcularCuota(monto, tasaMensual, plazo, tipoPlazo) {
    const cfg = TIPO_PLAZO[tipoPlazo] || TIPO_PLAZO.meses;
    const numCuotas = plazo * cfg.multiplicadorPlazo;
    const tasa = (tasaMensual * cfg.factorTasa) / 100;
    if (tasa == 0) return Math.round(monto / numCuotas * 100) / 100;
    return Math.round(monto * (tasa * Math.pow(1 + tasa, numCuotas)) / (Math.pow(1 + tasa, numCuotas) - 1) * 100) / 100;
}

function generarTablaAmortizacion(monto, tasaMensual, plazo, tipoPlazo, fechaInicio) {
    const cfg = TIPO_PLAZO[tipoPlazo] || TIPO_PLAZO.meses;
    const numCuotas = plazo * cfg.multiplicadorPlazo;
    const tasa = (tasaMensual * cfg.factorTasa) / 100;
    let cuota = calcularCuota(monto, tasaMensual, plazo, tipoPlazo);
    let saldo = monto;
    const tabla = [];
    const hoy = fechaInicio ? new Date(fechaInicio + 'T00:00:00') : new Date();

    for (let i = 1; i <= numCuotas; i++) {
        let interes = Math.round(saldo * tasa * 100) / 100;
        let capital = Math.round((cuota - interes) * 100) / 100;
        saldo = Math.round((saldo - capital) * 100) / 100;

        if (i === numCuotas) {
            capital += saldo;
            cuota += saldo;
            saldo = 0;
        }

        const fecha = new Date(hoy);
        if (tipoPlazo === 'meses') {
            fecha.setMonth(fecha.getMonth() + i);
        } else if (tipoPlazo === 'anios') {
            fecha.setFullYear(fecha.getFullYear() + i);
        } else {
            fecha.setDate(fecha.getDate() + i * cfg.divisorFecha);
        }

        tabla.push({
            cuota: i,
            fecha: fecha.toLocaleDateString('es-VE'),
            cuotaTotal: cuota,
            capital: capital,
            interes: interes,
            saldo: Math.max(0, saldo)
        });
    }

    return {
        tabla: tabla,
        cuotaPeriodo: cuota,
        numCuotas: numCuotas,
        totalIntereses: Math.round((cuota * numCuotas - monto) * 100) / 100,
        totalPagar: Math.round(cuota * numCuotas * 100) / 100
    };
}

function initDarkMode() {
    const toggle = document.getElementById('darkModeToggle');
    if (!toggle) return;

    const saved = localStorage.getItem('darkMode');
    if (saved === 'true') {
        document.body.classList.add('dark-mode');
        updateDarkModeIcon(true);
    }

    toggle.addEventListener('click', () => {
        const isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('darkMode', isDark);
        updateDarkModeIcon(isDark);
    });
}

function updateDarkModeIcon(isDark) {
    const toggle = document.getElementById('darkModeToggle');
    if (!toggle) return;
    const icon = toggle.querySelector('i');
    icon.className = isDark ? 'bi bi-sun' : 'bi bi-moon';
}

function initSidebar() {
    const toggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (!toggle || !sidebar) return;

    toggle.addEventListener('click', () => {
        sidebar.classList.toggle('mobile-open');
        overlay.classList.toggle('active');
    });

    if (overlay) {
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
        });
    }
}

function initBackToTop() {
    const btn = document.getElementById('backToTop');
    if (!btn) return;

    window.addEventListener('scroll', () => {
        btn.classList.toggle('visible', window.scrollY > 300);
    });

    btn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

function initFormLoading() {
    document.querySelectorAll('form[data-loading]').forEach(form => {
        form.addEventListener('submit', function () {
            const btn = this.querySelector('[type="submit"]');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Guardando...';
            }
        });
    });
}

function initScrollAnimations() {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('animate-in');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.card, .stat-card, .table-responsive').forEach(el => {
        observer.observe(el);
    });
}

function initTooltips() {
    if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) return;
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        new bootstrap.Tooltip(el);
    });
}

function confirmarAccion(mensaje) {
    return confirm(mensaje || '¿Está seguro de realizar esta acción?');
}

function confirmarLimpiarDb() {
    return confirm('¿Limpiar toda la base de datos?\n\nSe borrarán TODOS los préstamos, pagos, mis deudas y pagos de deudas, y se restaurarán los clientes y acreedores de prueba. Esta acción NO se puede deshacer.') && confirm('¿Confirmas que deseas vaciar la base de datos?');
}

function imprimirRecibo() {
    window.print();
}

function initFloatingLabels() {
    document.querySelectorAll('.floating-group select').forEach(function (select) {
        function toggleHasValue() {
            var group = select.closest('.floating-group');
            if (!group) return;
            if (select.value && select.value !== '') {
                group.classList.add('has-value');
            } else {
                group.classList.remove('has-value');
            }
        }
        select.addEventListener('change', toggleHasValue);
        toggleHasValue();
    });
}

function initClickableRows() {
    document.querySelectorAll('table tbody tr[data-href]').forEach(function (tr) {
        tr.addEventListener('click', function (e) {
            if (e.target.closest('a, button, .dropdown, input, select, textarea, label')) return;
            window.location.href = tr.getAttribute('data-href');
        });
    });
}

function initOnboardingModal() {
    const modal = document.getElementById('onboardingModal');
    if (!modal) return;

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        new bootstrap.Modal(modal).show();
    } else {
        modal.classList.add('show');
        modal.style.display = 'block';
        if (!document.querySelector('.modal-backdrop')) {
            const bd = document.createElement('div');
            bd.className = 'modal-backdrop fade show';
            document.body.appendChild(bd);
        }
        document.body.classList.add('modal-open');
    }

    const action = document.getElementById('onboardingAction');
    const preg = document.getElementById('onbPregunta');
    const mont = document.getElementById('onbMonto');
    const btnSi = document.getElementById('onbSi');
    const btnSet = document.getElementById('onbSet');
    const btnVolver = document.getElementById('onbVolver');
    const montoInput = document.getElementById('onbMontoInput');
    const pregBtns = document.getElementById('onbPreguntaBtns');
    const montBtns = document.getElementById('onbMontoBtns');

    function mostrarMonto() {
        preg.style.display = 'none';
        pregBtns.style.display = 'none';
        mont.style.display = '';
        montBtns.style.display = '';
        montoInput.disabled = false;
        montoInput.focus();
    }

    function volver() {
        mont.style.display = 'none';
        montBtns.style.display = 'none';
        preg.style.display = '';
        pregBtns.style.display = '';
        montoInput.disabled = true;
        action.value = 'skip';
    }

    if (btnSi) btnSi.addEventListener('click', mostrarMonto);
    if (btnSet) btnSet.addEventListener('click', function () { action.value = 'set'; });
    if (btnVolver) btnVolver.addEventListener('click', volver);
}

document.addEventListener('DOMContentLoaded', function () {
    [initOnboardingModal, initDarkMode, initSidebar, initBackToTop, initFormLoading, initScrollAnimations, initTooltips, initFloatingLabels, initClickableRows].forEach(function (fn) {
        try {
            fn();
        } catch (e) {
            console.error('Error de inicialización:', e);
        }
    });
});
