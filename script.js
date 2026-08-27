/**
 * script.js
 * ------------------------------------------------------------------
 * Front-end logic for dashboard.php (Color Print order dashboard).
 *
 * This file was renamed from the misspelled "sript.js" and now also
 * contains all the JavaScript that used to live in inline <script>
 * blocks inside dashboard.php. Keeping it in one external file makes
 * the markup easier to read and lets the browser cache the script
 * instead of re-downloading it as part of the HTML on every request.
 *
 * A few small, PHP-generated <script> snippets were LEFT in
 * dashboard.php on purpose because they depend on server-side values
 * (e.g. the newly-inserted order ID) that only PHP knows at request
 * time — those could not be moved here without turning them into an
 * AJAX call, which was outside the scope of this cleanup.
 *
 * Load order: this file must be included AFTER jQuery, jQuery UI,
 * Select2, Tippy/Popper, AOS and SweetAlert2, since several sections
 * below depend on the globals those libraries expose ($, Swal, tippy,
 * AOS).
 * ------------------------------------------------------------------
 */

/* ============================================================
 * SECTION: Legacy client / order helpers
 * ------------------------------------------------------------
 * These functions predate the Select2-based client picker and the
 * AJAX "quiet refresh" flow added further down this file. A search of
 * dashboard.php did not turn up any remaining calls to
 * toggleClientFields(), fetchClientDetails(), validateDueDateTime(),
 * submitOrderForm(), submitEditClientForm() or toggleClientDetails() —
 * they appear to have been superseded by the "Select2 init" and
 * "Add-order form submit" sections below. They're kept here (rather
 * than deleted) in case another page still references them; please
 * double-check before removing them outright.
 * ============================================================ */

/**
 * Formats a date string as "<Romanian day name>, dd.mm" (no year).
 * Example: "Luni, 07.04"
 * @param {string} dateString - Any string parseable by `new Date()`.
 * @returns {string} The formatted day + date.
 */
function formatDateWithoutYearWithDay(dateString) {
  let date = new Date(dateString);
  let day = date.getDate();
  let month = date.getMonth() + 1; // Months are zero-based
  let daysOfWeek = [
    'Duminică',
    'Luni',
    'Marți',
    'Miercuri',
    'Joi',
    'Vineri',
    'Sâmbătă',
  ];
  let dayOfWeek = daysOfWeek[date.getDay()];
  return (
    dayOfWeek +
    ', ' +
    (day < 10 ? '0' : '') +
    day +
    '.' +
    (month < 10 ? '0' : '') +
    month
  );
}

/**
 * Formats how many days remain until an order's due date, prefixed
 * with the Romanian name of the due-date's weekday. Never returns a
 * negative count — anything already past due is clamped to "0 zile rămase".
 * @param {string} dueDate - The order's due date, parseable by `new Date()`.
 * @returns {string} e.g. "Marți, 3 zile rămase".
 */
function formatRemainingDays(dueDate) {
  let currentDate = new Date();
  let dueDateObj = new Date(dueDate);
  let timeDiff = dueDateObj - currentDate;
  let daysDiff = Math.ceil(timeDiff / (1000 * 3600 * 24));
  let daysOfWeek = [
    'Duminică',
    'Luni',
    'Marți',
    'Miercuri',
    'Joi',
    'Vineri',
    'Sâmbătă',
  ];
  let dayOfWeek = daysOfWeek[dueDateObj.getDay()];
  if (daysDiff >= 0) {
    return dayOfWeek + ', ' + daysDiff + ' zile rămase';
  } else {
    return dayOfWeek + ', 0 zile rămase';
  }
}

/**
 * Shows either the "new client" fields or the "existing client"
 * details button, depending on whether a client is selected in the
 * #client_id dropdown. When an existing client is chosen, kicks off
 * fetchClientDetails() to populate the read-only details panel.
 */
function toggleClientFields() {
  let clientSelect = document.getElementById('client_id');
  let newClientFields = document.getElementById('new_client_fields');
  let clientDetailsButton = document.getElementById('client_details_button');
  let clientDetails = document.getElementById('client_details');
  if (clientSelect.value === '') {
    newClientFields.style.display = 'block';
    clientDetailsButton.style.display = 'none';
    clientDetails.style.display = 'none';
  } else {
    newClientFields.style.display = 'none';
    clientDetailsButton.style.display = 'block';
    fetchClientDetails(clientSelect.value);
  }
}

/**
 * Fetches a single client's details from dashboard.php and fills in
 * both the read-only display fields and the hidden edit-form fields.
 * @param {string|number} clientId - The client_id to look up.
 */
function fetchClientDetails(clientId) {
  let xhr = new XMLHttpRequest();
  xhr.open(
    'GET',
    'dashboard.php?fetch_client_details=true&client_id=' + clientId,
    true,
  );
  xhr.onreadystatechange = function () {
    if (xhr.readyState == 4 && xhr.status == 200) {
      let client = JSON.parse(xhr.responseText);
      document.getElementById('client_name_display').innerText =
        client.client_name;
      document.getElementById('client_email_display').innerText =
        client.client_email;
      document.getElementById('client_phone_display').innerText =
        client.client_phone;
      document.getElementById('client_id_edit').value = client.client_id;
      document.getElementById('client_name_edit').value = client.client_name;
      document.getElementById('client_email_edit').value = client.client_email;
      document.getElementById('client_phone_edit').value = client.client_phone;
      document.getElementById('client_details').style.display = 'block';
    }
  };
  xhr.send();
}

/**
 * Blocks form submission unless the chosen due date/time is still in
 * the future. Shows a plain `alert()` when validation fails.
 * @returns {boolean} true if the due date is valid (in the future).
 */
function validateDueDateTime() {
  let dueDate = document.getElementById('due_date').value;
  let dueDateObj = new Date(dueDate);
  let currentDate = new Date();
  if (dueDateObj <= currentDate) {
    alert('Data livrării trebuie să fie în viitor.');
    return false;
  }
  return true;
}

/**
 * Submits the "add order" form via AJAX instead of a full page POST.
 * Validates the due date first, then reloads the page on success.
 * @param {Event} event - The form's submit event.
 */
function submitOrderForm(event) {
  event.preventDefault();
  if (!validateDueDateTime()) {
    return;
  }
  let formData = new FormData(document.getElementById('orderForm'));
  let xhr = new XMLHttpRequest();
  xhr.open('POST', 'dashboard.php', true);
  xhr.onreadystatechange = function () {
    if (xhr.readyState == 4 && xhr.status == 200) {
      let response = JSON.parse(xhr.responseText);
      if (response.success) {
        alert('Comanda a fost adăugată cu succes.');
        location.reload();
      } else {
        alert('Eroare la adăugarea comenzii.');
      }
    }
  };
  formData.append('add_order', true);
  xhr.send(formData);
}

/**
 * Submits the "edit client" form via AJAX to edit_client.php.
 * @param {Event} event - The form's submit event.
 */
function submitEditClientForm(event) {
  event.preventDefault();
  let formData = new FormData(document.getElementById('editClientForm'));
  let xhr = new XMLHttpRequest();
  xhr.open('POST', 'edit_client.php', true);
  xhr.onreadystatechange = function () {
    if (xhr.readyState == 4 && xhr.status == 200) {
      alert('Detaliile clientului au fost actualizate cu succes.');
    }
  };
  xhr.send(formData);
}

/**
 * Re-fetches and re-displays the selected client's details.
 * Functionally identical to fetchClientDetails(), but reads the
 * client id from the #client_id select itself and does nothing if
 * no client is currently selected.
 */
function toggleClientDetails() {
  let clientSelect = document.getElementById('client_id');
  let clientId = clientSelect.value;
  if (clientId) {
    let xhr = new XMLHttpRequest();
    xhr.open(
      'GET',
      'dashboard.php?fetch_client_details=true&client_id=' + clientId,
      true,
    );
    xhr.onreadystatechange = function () {
      if (xhr.readyState == 4 && xhr.status == 200) {
        let client = JSON.parse(xhr.responseText);
        document.getElementById('client_name_display').innerText =
          client.client_name;
        document.getElementById('client_email_display').innerText =
          client.client_email;
        document.getElementById('client_phone_display').innerText =
          client.client_phone;
        document.getElementById('client_id_edit').value = client.client_id;
        document.getElementById('client_name_edit').value = client.client_name;
        document.getElementById('client_email_edit').value =
          client.client_email;
        document.getElementById('client_phone_edit').value =
          client.client_phone;
        document.getElementById('client_details').style.display = 'block';
      }
    };
    xhr.send();
  }
}

// NOTE: this file used to also register a DOMContentLoaded handler here
// that forced #new_client_fields to display:none via an inline style,
// and then tried to fill in #currentYear / #due_date / #due_time inputs.
// None of those target elements match the current markup any more —
// the "new client" section is now shown/hidden purely through the
// .collapsible/.collapsed CSS class (see toggleClientFieldsVisibility()
// and syncClientRequiredState() below), the due date is now the
// #datePickerSelect dropdown, there's no separate due_time input, and
// there's no #currentYear element in the footer. That old handler was
// dead weight at best, and actively bugged at worst: it set an inline
// display:none on #new_client_fields that the newer class-based
// show/hide logic never clears, permanently hiding the new-client
// name/phone fields. It has been removed for that reason.

/* ============================================================
 * SECTION: Global alert/toast helpers
 * ------------------------------------------------------------
 * Small SweetAlert2 wrappers used throughout the rest of this file
 * (and previously defined ahead of everything else in dashboard.php,
 * since several handlers below call showAlert()/Toast directly).
 * ============================================================ */

/**
 * Shows a SweetAlert2 popup with sensible defaults.
 * @param {Object} [options]
 * @param {string} [options.title='Notificare'] - Dialog title.
 * @param {string} [options.text=''] - Dialog body text.
 * @param {string} [options.icon='info'] - SweetAlert2 icon name.
 * @param {number|null} [options.timer=null] - Auto-close delay in ms.
 * @returns {Promise} The SweetAlert2 promise for the popup.
 */
function showAlert({
  title = 'Notificare',
  text = '',
  icon = 'info',
  timer = null,
} = {}) {
  return Swal.fire({
    icon,
    title,
    text,
    timer,
    timerProgressBar: !!timer,
    confirmButtonText: 'OK',
  });
}

// Shared toast instance for lightweight, auto-dismissing confirmations
// (success/error messages after AJAX calls) throughout the dashboard.
const Toast = Swal.mixin({
  toast: true,
  position: 'center',
  showConfirmButton: false,
  timer: 2500,
  timerProgressBar: true,
});

/* ============================================================
 * SECTION: Select2 initialization (filters + client picker)
 * ------------------------------------------------------------
 * Wires up Select2 on the filter dropdowns, the "new order" client
 * search box, and the client edit modal.
 * ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
  // Initialize Select2 on select elements
  $(document).ready(function () {
    $(
      '#status_filter, #assigned_filter, #category_filter, #assigned_to, #category_id',
    ).select2({
      dropdownAutoWidth: true,
      width: 'auto',
    });
  });

  $('#client_filter').select2({
    dropdownAutoWidth: true,
    width: 'auto',
    placeholder: 'Toți',
    allowClear: true,
    ajax: {
      url: 'fetch_clients.php',
      dataType: 'json',
      delay: 250,
      data: function (params) {
        return {
          search_clients: 1,
          q: params.term,
        };
      },
      processResults: function (data) {
        return {
          results: data,
        };
      },
      cache: true,
    },
    templateResult: formatClient,
    templateSelection: formatClientSelection,
  });

  $('#client_id').select2({
    dropdownAutoWidth: true,
    width: 'auto',
    placeholder: 'Nume sau telefon client',
    allowClear: true,
    ajax: {
      url: 'fetch_clients.php', // Update the URL to point to fetch_clients.php
      dataType: 'json',
      delay: 250,
      data: function (params) {
        return {
          search_clients: 1,
          q: params.term, // search term
        };
      },
      processResults: function (data) {
        return {
          results: data,
        };
      },
      cache: true,
    },
    templateResult: formatClient, // custom formatting function for results
    templateSelection: formatClientSelection, // custom formatting function for selected item
  });

  // Custom formatting function for results
  function formatClient(client) {
    if (!client.id) {
      return client.text;
    }

    let $client = $(
      '<div class="select2-result-client">' +
        '<span style="font-weight: bold;">' +
        client.client_name +
        '</span>' +
        '<div style="font-style: normal;">' +
        client.client_phone +
        '</div>' +
        '</div>',
    );

    return $client;
  }

  // Custom formatting function for selected item
  function formatClientSelection(client) {
    if (!client.id) {
      return client.text;
    }

    return client.client_name;
  }

  // Function to toggle visibility of new client fields based on client selection
  function toggleClientFieldsVisibility() {
    let clientId = $('#client_id').val();
    if (clientId) {
      $('#new_client_fields').addClass('collapsed');
      $('#edit_client_button').show();
    } else {
      $('#new_client_fields').removeClass('collapsed');
      $('#edit_client_button').hide();
    }
  }

  // Listen for changes on the client_id select element
  $('#client_id').on('change', toggleClientFieldsVisibility);

  // Initial check to set the visibility based on the current selection
  toggleClientFieldsVisibility();

  // Function to open the edit modal
  function openEditModal(clientId) {
    $('#editClientModal').css('display', 'block');
    // Fetch client details and populate the form
    fetch('get_client.php?client_id=' + clientId)
      .then((response) => response.json())
      .then((data) => {
        $('#edit_client_id').val(data.client_id);
        $('#edit_client_name').val(data.client_name);
        $('#edit_client_phone').val(data.client_phone);
        $('#edit_client_email').val(data.client_email);
      })
      .catch((error) => console.error('Error:', error));
  }

  // Close the modal when the user clicks on <span> (x)
  $('.close').on('click', function () {
    $('#editClientModal').css('display', 'none');
  });

  // Handle edit form submission
  $('#editClientForm').on('submit', function (event) {
    event.preventDefault();
    let formData = new FormData(this);
    fetch('update_client.php', {
      method: 'POST',
      body: formData,
    })
      .then((response) => response.text())
      .then((data) => {
        Toast.fire({
          icon: 'success',
          title: 'Client actualizat!',
        });
        $('#editClientModal').css('display', 'none');
        $('#client_id').trigger('change');
      })
      .catch((error) => console.error('Error:', error));
  });

  // Add event listener for the edit button.
  // Bound to the inner <button> (not the wrapping div) so the click area
  // matches the button's visual size — otherwise the wrapping block-level
  // div extends to the full form-group width and every click within
  // that row would open the edit modal.
  $('#editClientTrigger').on('click', function (e) {
    e.stopPropagation();
    let clientId = $('#client_id').val();
    if (clientId) {
      openEditModal(clientId);
    }
  });
});

// Add animating class on open
$(document).on('select2:open', function (e) {
  let $dropdown = $(e.target).data('select2').$dropdown;
  $dropdown.addClass('animating');
  setTimeout(function () {
    $dropdown.removeClass('animating');
  }, 500);
});

// Delay Select2 close to allow exit animation
$(document).on('select2:closing', function (e) {
  let $dropdown = $('.select2-dropdown');

  if (!$dropdown.hasClass('is-closing')) {
    e.preventDefault();
    $dropdown.addClass('is-closing');

    setTimeout(function () {
      $(e.target).select2('close');
    }, 500);
  } else {
    $dropdown.removeClass('is-closing');
  }
});

/* ============================================================
 * SECTION: "Add order" due-date picker
 * ------------------------------------------------------------
 * Populates #datePickerSelect with the next 90 calendar days
 * (Sundays skipped, since the shop is closed) and, if Select2 is
 * available, upgrades it into a searchable dropdown.
 * ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
  const select = document.getElementById('datePickerSelect');
  if (!select) return; // date picker only exists on dashboard.php
  const today = new Date();

  const daysToGenerate = 90; // only 90 days ahead

  let daysAdded = 0;
  let i = 0;

  while (daysAdded < daysToGenerate) {
    const date = new Date();
    date.setDate(today.getDate() + i);

    // Skip Sundays (getDay() === 0 means Sunday)
    if (date.getDay() !== 0) {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');

      const label = date.toLocaleDateString('ro-RO', {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
      });

      const option = new Option(label, `${year}-${month}-${day}`);

      if (daysAdded === 0) {
        option.selected = true;
      }

      select.add(option);
      daysAdded++;
    }

    i++;
  }

  // Optional: Select2 styling
  if (typeof $ !== 'undefined' && $.fn.select2) {
    $(select).select2({
      placeholder: 'Alege o dată',
      dropdownAutoWidth: true,
      width: 'auto',
    });
  }
});

/* ============================================================
 * SECTION: Order slider panel + "quiet" AJAX refresh
 * ------------------------------------------------------------
 * Everything related to the off-canvas order/statistics slider
 * panel, plus the shared quietRefresh() mechanism that re-fetches
 * dashboard.php in the background and swaps in the updated table,
 * pinned-orders list and pagination without a full page reload.
 * quietRefresh() is exposed on window so the filters/sort/pagination
 * section further down (and the slider-close handler here) can both
 * call into it.
 * ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
  const sliderPanel = document.getElementById('orderSliderPanel');
  const sliderBackdrop = document.getElementById('orderSliderBackdrop');
  const sliderIframe = document.getElementById('orderSliderIframe');
  const closeSliderBtn = document.getElementById('closeOrderSlider');

  if (!sliderPanel || !sliderBackdrop || !sliderIframe || !closeSliderBtn) {
    return; // slider markup only exists on dashboard.php
  }

  const sliderTitle = sliderPanel.querySelector('.order-slider-header h3');

  // --- 1. SLIDER LOGIC FOR ORDERS ---
  function openOrderSlider(orderId) {
    if (sliderTitle) {
      sliderTitle.innerHTML =
        '<i class="fa-solid fa-file-invoice"></i> Detalii Comandă';
    }
    sliderBackdrop.style.display = 'block';
    sliderIframe.src = 'view_order.php?order_id=' + orderId + '&embedded=1';

    setTimeout(() => {
      sliderPanel.classList.add('open');
      sliderBackdrop.classList.add('open');
    }, 10);

    document.body.style.overflow = 'hidden';
  }

  // --- NEW: SLIDER LOGIC FOR STATISTICS ---
  function openStatsSlider() {
    if (sliderTitle) {
      sliderTitle.innerHTML =
        '<i class="fa-solid fa-chart-line"></i> Statistici Comenzi';
    }
    sliderBackdrop.style.display = 'block';
    sliderIframe.src = 'statistics.php?embedded=1';

    setTimeout(() => {
      sliderPanel.classList.add('open');
      sliderBackdrop.classList.add('open');
    }, 10);

    document.body.style.overflow = 'hidden';
  }

  // Expose globally so other scripts / inline HTML can access them
  window.openOrderSlider = openOrderSlider;
  window.openStatsSlider = openStatsSlider;

  function closeOrderSlider() {
    sliderPanel.classList.remove('open');
    sliderBackdrop.classList.remove('open');
    document.body.style.overflow = '';

    setTimeout(() => {
      sliderIframe.src = '';
      sliderBackdrop.style.display = 'none';

      // Trigger the quiet refresh instead of a full page reload
      quietRefresh();
    }, 1400);
  }

  closeSliderBtn.addEventListener('click', closeOrderSlider);
  sliderBackdrop.addEventListener('click', closeOrderSlider);

  document.addEventListener('keydown', function (e) {
    // 1. Existing ESC key logic
    if (e.key === 'Escape' && sliderPanel.classList.contains('open')) {
      closeOrderSlider();
    }

    // 2. Intercept Ctrl+P (or Cmd+P on Mac) when the slider is open
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
      if (sliderPanel.classList.contains('open')) {
        e.preventDefault(); // Stop browser from printing the main dashboard page

        try {
          const iframeWindow = sliderIframe.contentWindow;
          const iframeDoc = iframeWindow.document;

          // Adjust the CSS selector below (#printBtn or .print-button) to match your button in view_order.php
          const printButton =
            iframeDoc.querySelector('#printBtn') ||
            iframeDoc.querySelector('.print-button');

          if (printButton) {
            printButton.click(); // Trigger the exact button click logic
          } else {
            iframeWindow.print(); // Fallback to direct iframe window print
          }
        } catch (err) {
          console.error('Could not trigger iframe print:', err);
        }
      }
    }
  });

  // --- 2. EVENT BINDING ---
  // Grouped into a function so it can be re-run after fetching new HTML
  function bindOrderClickEvents() {
    document.querySelectorAll('.order-row').forEach((row) => {
      // Inline onclick from the PHP markup has already been parsed into
      // a property listener; removeAttribute() wouldn't touch it. Setting
      // onclick = null detaches the inline handler so this JS handler is
      // the only one that runs.
      row.onclick = null;
      row.style.cursor = 'pointer';

      row.addEventListener('click', function (e) {
        e.preventDefault();
        const orderId = this.getAttribute('data-order-id');
        if (orderId) openOrderSlider(orderId);
      });
    });

    document.querySelectorAll('.pinned-section a').forEach((link) => {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        const href = this.getAttribute('href');
        const urlParams = new URLSearchParams(href.split('?')[1]);
        const orderId = urlParams.get('order_id');
        if (orderId) openOrderSlider(orderId);
      });
    });
  }

  // Bind events initially on page load
  bindOrderClickEvents();

  // --- 3. QUIET REFRESH (AJAX) ---
  // Shared by two callers: the slider-close refresh below (same URL, and it
  // SHOULD reset the "add order" sidebar form), and the filters/sort/pagination
  // script further down the page (a NEW url from the address bar's point of
  // view, and it should NOT reset the sidebar form — someone could be mid-way
  // through drafting a new order while just browsing/filtering the table).
  let refreshInProgress = false;

  function quietRefresh(targetUrl, { resetForm = true } = {}) {
    if (refreshInProgress) return Promise.resolve();
    refreshInProgress = true;
    const url = targetUrl || window.location.href;

    return fetch(url)
      .then((response) => response.text())
      .then((html) => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        // Runs FIRST, unconditionally — nothing below can ever block this again
        if (resetForm) resetOrderForm();

        const sectionsToUpdate = [
          '.pinned-section',
          '.main-content tbody',
          '.pagination',
        ];

        let rowDirections = new Map();
        try {
          rowDirections = computeRowDirections(
            document.querySelector('.main-content tbody'),
            doc.querySelector('.main-content tbody'),
          );
        } catch (err) {
          console.error('Row direction calc failed:', err);
        }

        try {
          animateStatCards(
            document.querySelector('.stats-banner'),
            doc.querySelector('.stats-banner'),
          );
        } catch (err) {
          console.error('Stat animation failed:', err);
        }

        const applyUpdate = () => {
          sectionsToUpdate.forEach((selector) => {
            const currentSection = document.querySelector(selector);
            const updatedSection = doc.querySelector(selector);
            if (currentSection && updatedSection) {
              currentSection.innerHTML = updatedSection.innerHTML;
            }
          });

          // Sweep-highlight rows that changed rank (e.g. after a sort
          // change) using the .row-moved-up/-down CSS that already existed
          // but was never actually applied to any element.
          rowDirections.forEach((direction, orderId) => {
            const row = document.querySelector(
              `.main-content tbody tr[data-order-id="${orderId}"]`,
            );
            if (row) {
              row.classList.add(
                direction === 'up' ? 'row-moved-up' : 'row-moved-down',
              );
              setTimeout(
                () => row.classList.remove('row-moved-up', 'row-moved-down'),
                750,
              );
            }
          });

          tagElementsForTransition();
          bindOrderClickEvents();
          if (typeof window.initTippy === 'function') window.initTippy();
          // Pagination links live inside the section we just replaced, so
          // any listeners on the old <a> tags are gone — rebind if present.
          if (typeof window.bindPaginationClickEvents === 'function')
            window.bindPaginationClickEvents();
        };

        try {
          if (document.startViewTransition) {
            tagElementsForTransition();
            const transition = document.startViewTransition(applyUpdate);
            transition.finished
              .catch(() => {}) // an interrupted transition isn't an error worth surfacing
              .finally(() => {
                clearTransitionNames();
                refreshInProgress = false;
              });
          } else {
            applyUpdate();
            refreshInProgress = false;
          }
        } catch (err) {
          console.error(
            'View transition failed, applying update directly:',
            err,
          );
          applyUpdate();
          refreshInProgress = false;
        }

        // Keep the address bar (and reload/back-button behavior) in sync
        // with whatever is now on screen.
        if (url !== window.location.href) {
          history.pushState(
            {
              quietNav: true,
            },
            '',
            url,
          );
        }
      })
      .catch((error) => {
        console.error('Eroare la quiet refresh:', error);
        refreshInProgress = false;
      });
  }

  // Expose globally so the filters/sort/pagination script (further down the
  // page) can trigger the same refresh instead of a full navigation.
  window.quietRefresh = quietRefresh;
  // Expose the stat-card odometer animation so the background stat-only
  // refresh (see the "Server-side quiet refresh" section at the file end) can
  // roll the numbers without needing a full table refresh.
  window.animateStatCards = animateStatCards;

  // Back/forward after a quiet filter/sort/page change should re-render too —
  // pushState alone only updates the address bar, not the page content.
  window.addEventListener('popstate', function () {
    quietRefresh(window.location.href, {
      resetForm: false,
    });
  });

  function resetOrderForm() {
    const orderForm = document.getElementById('orderForm');
    if (orderForm) {
      // 1. Reset standard form fields (restores native <select> elements to their defaults)
      orderForm.reset();

      if (typeof jQuery !== 'undefined') {
        // 2. Clear the AJAX client search completely
        if ($('#client_id').length) {
          $('#client_id').val(null).trigger('change');
        }

        // 3. Sync the Select2 UI for Date and Operator to reflect the native form reset
        $('#datePickerSelect, #assigned_to').trigger('change');
      }
      // Keep the server-side quiet-refresh dirty-guard in sync: after a real
      // reset the form has no user input, so it must NOT read as "dirty".
      if (typeof window.markOrderFormClean === 'function') {
        window.markOrderFormClean();
      }
    }
  }

  function tagElementsForTransition() {
    document
      .querySelectorAll('.main-content tbody tr.order-row[data-order-id]')
      .forEach((row) => {
        row.style.viewTransitionName = `order-row-${row.dataset.orderId}`;
      });
  }

  function clearTransitionNames() {
    document
      .querySelectorAll('[style*="view-transition-name"]')
      .forEach((el) => {
        el.style.viewTransitionName = '';
      });
  }

  function computeRowDirections(currentSection, updatedSection) {
    const directions = new Map();
    if (!currentSection || !updatedSection) return directions;

    const oldIds = Array.from(
      currentSection.querySelectorAll('tr.order-row[data-order-id]'),
    ).map((r) => r.dataset.orderId);
    const newIds = Array.from(
      updatedSection.querySelectorAll('tr.order-row[data-order-id]'),
    ).map((r) => r.dataset.orderId);

    oldIds.forEach((id, oldIndex) => {
      const newIndex = newIds.indexOf(id);
      if (newIndex === -1) return;
      if (newIndex < oldIndex) directions.set(id, 'up');
      else if (newIndex > oldIndex) directions.set(id, 'down');
    });

    return directions;
  }

  // --- Stat cards: odometer-style digit roll ---

  function animateStatCards(currentSection, updatedSection) {
    if (!currentSection || !updatedSection) return;
    const keys = [
      'card-overdue',
      'card-active',
      'card-completed',
      'card-deliver-today',
      'card-delivered-today',
    ];

    keys.forEach((key) => {
      const numberEl = currentSection.querySelector(`.${key} h3`);
      const newNumberEl = updatedSection.querySelector(`.${key} h3`);
      if (!numberEl || !newNumberEl) return;

      const oldValue =
        parseInt(numberEl.dataset.value ?? numberEl.textContent, 10) || 0;
      const newValue = parseInt(newNumberEl.textContent, 10) || 0;
      numberEl.dataset.value = newValue; // canonical value, independent of DOM structure

      if (oldValue === newValue) return; // untouched — no animation

      const cardEl = numberEl.closest('.stat-card');
      const direction = newValue > oldValue ? 'up' : 'down';

      cardEl.classList.add(`stat-${direction}`);
      setTimeout(() => cardEl.classList.remove('stat-up', 'stat-down'), 700);
      rollOdometer(numberEl, oldValue, newValue, direction);
    });
  }

  function rollOdometer(container, oldValue, newValue, direction) {
    const maxLen = Math.max(String(oldValue).length, String(newValue).length);
    const oldDigits = String(oldValue).padStart(maxLen, '0').split('');
    const newDigits = String(newValue).padStart(maxLen, '0').split('');

    container.innerHTML = '';

    newDigits.forEach((newDigit, i) => {
      const oldDigit = oldDigits[i];
      const slot = document.createElement('span');
      slot.className = 'digit-slot';

      if (oldDigit === newDigit) {
        slot.innerHTML = `<span class="digit-inner">${newDigit}</span>`;
      } else {
        slot.innerHTML = `
                <span class="digit-inner digit-out digit-${direction}">${oldDigit}</span>
                <span class="digit-inner digit-in digit-${direction}">${newDigit}</span>
            `;
        setTimeout(() => {
          slot.innerHTML = `<span class="digit-inner">${newDigit}</span>`; // settle for clean future reads
        }, 420);
      }
      container.appendChild(slot);
    });
  }
});

/* ============================================================
 * SECTION: "Add order" form submit (AJAX)
 * ------------------------------------------------------------
 * Intercepts the add-order form submit, posts it via fetch(), and on
 * success shows a success toast and opens the new order directly in
 * the slider panel (falling back to a full navigation if the slider
 * script hasn't loaded for some reason).
 * ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
  const orderForm = document.getElementById('orderForm');
  if (!orderForm) return; // form only exists on dashboard.php

  orderForm.addEventListener('submit', function (event) {
    event.preventDefault(); // Prevent default form submission

    fetch('dashboard.php', {
      method: 'POST',
      body: new FormData(this),
    })
      .then((response) => response.text())
      .then((data) => {
        if (data.includes('Comanda a fost adăugată cu succes! 🚀 🚀 🚀 ')) {
          Toast.fire({
            icon: 'success',
            title: 'Comanda a fost adăugată!',
          });
          this.reset();
          // The change was made by THIS user: suppress the server-side quiet
          // refresh for a short window so we don't echo our own action back,
          // and record that the add-order form is clean (nothing to protect).
          window.__autoRefreshSuppressUntil = Date.now() + 10000;
          if (typeof window.markOrderFormClean === 'function') {
            window.markOrderFormClean();
          }

          const match = data.match(/order_id=(\d+)/);
          const orderId = match ? match[1] : null;

          if (orderId) {
            Swal.fire({
              icon: 'success',
              title: 'Comanda a fost adăugată!',
              text: 'Se deschide panoul comenzii...',
              showConfirmButton: false,
              timer: 1000,
              timerProgressBar: true,
              position: 'center',
            }).then(() => {
              if (typeof window.openOrderSlider === 'function') {
                window.openOrderSlider(orderId);
              } else {
                // Fallback if slider function is unavailable
                const returnUrl = document.querySelector(
                  'input[name="return"]',
                ).value;
                window.location.href =
                  'view_order.php?order_id=' +
                  orderId +
                  (returnUrl ? '&return=' + encodeURIComponent(returnUrl) : '');
              }
            });
          }
        } else {
          showAlert({
            icon: 'error',
            title: 'Eroare',
            text: 'Nu s-a putut adăuga comanda: ' + data,
          });
        }
      })
      .catch((error) => {
        console.error('Error:', error);
        showAlert({
          icon: 'error',
          title: 'Eroare de rețea',
          text: 'A apărut o problemă la procesarea cererii.',
        });
      });
  });
});

/* ============================================================
 * SECTION: AOS (Animate On Scroll) init
 * ------------------------------------------------------------
 * Starts the AOS library that powers the fade/zoom-in effects on
 * the header and hero elements.
 * ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
  // Init AOS — library is only included on dashboard.php
  if (typeof AOS === 'undefined') return;
  AOS.init({
    duration: 800, // Adjust animation duration here
    once: true,
    mirror: false, // Start animation on scroll up as well
  });
});

/* ============================================================
 * SECTION: Header order search ("order_lookup")
 * ------------------------------------------------------------
 * A hand-rolled, Select2-styled autocomplete for the header search
 * box: debounces keystrokes, queries search_orders.php, renders a
 * custom results dropdown (with the matched term highlighted), and
 * supports full keyboard navigation (arrows/enter/escape) as well as
 * mouse selection. Selecting a result opens it in the order slider.
 * ============================================================ */
$(function () {
  let $input = $('#order_lookup');
  if (!$input.length) return;

  // Dropdown lives outside the header so it can never be clipped,
  // same reasoning the old select2 config had (dropdownParent: body).
  let $dropdown = $(
    '<div id="order_lookup_dropdown" class="select2-dropdown header-order-search select2-container--default">' +
      '<span class="select2-results"><ul class="select2-results__options"></ul></span>' +
      '</div>',
  ).appendTo('body');
  let $list = $dropdown.find('.select2-results__options');

  let currentTerm = '';
  let currentXhr = null;
  let debounceTimer = null;
  let results = [];
  let activeIndex = -1;
  let isOpen = false;
  let closeTimer = null;

  function highlightTerm(text, term) {
    if (!text) return '';
    if (!term) return text;
    let escaped = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    let regex = new RegExp('(' + escaped + ')', 'gi');
    return text.replace(regex, '<span class="highlight">$1</span>');
  }

  function renderOption(order, index) {
    let term = currentTerm;
    let phoneLine = order.client_phone
      ? '<div style="font-size:12px;color:#666;"><i class="fa-solid fa-phone" style="font-size:10px;"></i> ' +
        highlightTerm(order.client_phone, term) +
        '</div>'
      : '';
    return $(
      '<li class="select2-results__option" role="option" data-index="' +
        index +
        '" aria-selected="false">' +
        '<div><strong>#' +
        order.id +
        '</strong> – ' +
        highlightTerm(order.client_name, term) +
        '</div>' +
        phoneLine +
        '<div style="font-size:12px;color:#555;">' +
        highlightTerm(order.order_details, term) +
        '</div>' +
        '<div style="font-size:11px;color:#999;">' +
        highlightTerm(order.detalii_suplimentare, term) +
        '</div>' +
        '</li>',
    );
  }

  function positionDropdown() {
    let rect = $input[0].getBoundingClientRect();
    $dropdown.css({
      top: rect.bottom + 'px',
      left: rect.left + 'px',
      width: rect.width + 'px',
    });
  }

  function setActive(index) {
    $list
      .find('.select2-results__option')
      .removeClass('select2-results__option--highlighted')
      .attr('aria-selected', 'false');
    activeIndex = index;
    if (index < 0 || index >= results.length) return;
    let $opt = $list.find(
      '.select2-results__option[data-index="' + index + '"]',
    );
    $opt
      .addClass('select2-results__option--highlighted')
      .attr('aria-selected', 'true');
    let optEl = $opt[0];
    if (optEl && optEl.scrollIntoView)
      optEl.scrollIntoView({
        block: 'nearest',
      });
  }

  function selectOrder(order) {
    if (!order || !order.id) return;

    if (typeof window.openOrderSlider === 'function') {
      window.openOrderSlider(order.id);
    } else {
      let returnInput = document.querySelector(
        '#lookupForm input[name="return"]',
      );
      let returnUrl = returnInput ? returnInput.value : '';
      window.location.href =
        'view_order.php?order_id=' +
        order.id +
        (returnUrl ? '&return=' + encodeURIComponent(returnUrl) : '');
    }

    $input.val('');
    currentTerm = '';
    closeDropdown();
    $input.trigger('blur');
  }

  function openDropdown() {
    clearTimeout(closeTimer);
    if (isOpen) return;
    isOpen = true;
    positionDropdown();
    $dropdown.removeClass('is-closing').addClass('animating').show();
    setTimeout(function () {
      $dropdown.removeClass('animating');
    }, 260);
  }

  function closeDropdown() {
    if (!isOpen) return;
    isOpen = false;
    activeIndex = -1;
    $dropdown.addClass('is-closing');
    closeTimer = setTimeout(function () {
      $dropdown.hide().removeClass('is-closing');
    }, 260);
  }

  function showMessage(text) {
    $list.html('<li class="select2-results__message">' + text + '</li>');
  }

  function fetchResults(term) {
    if (currentXhr) currentXhr.abort();
    currentXhr = $.ajax({
      url: 'search_orders.php',
      dataType: 'json',
      data: {
        search_orders: 1,
        q: term,
      },
    })
      .done(function (data) {
        results = data || [];
        $list.empty();
        if (!results.length) {
          showMessage('Niciun rezultat');
        } else {
          results.forEach(function (order, index) {
            $list.append(renderOption(order, index));
          });
        }
        activeIndex = -1;
        openDropdown();
      })
      .fail(function (xhr, status) {
        if (status === 'abort') return;
        showMessage('Eroare la căutare');
        openDropdown();
      });
  }

  // Typing directly in the visible field is all it takes — no second
  // search box, one click (or focus) is enough to start searching.
  $input.on('input', function () {
    let term = $input.val().trim();
    currentTerm = term;
    clearTimeout(debounceTimer);

    if (term.length < 1) {
      if (currentXhr) currentXhr.abort();
      closeDropdown();
      return;
    }

    debounceTimer = setTimeout(function () {
      fetchResults(term);
    }, 250);
  });

  $input.on('focus', function () {
    if (currentTerm.length >= 1 && results.length) openDropdown();
  });

  $input.on('keydown', function (e) {
    if (
      !isOpen &&
      (e.key === 'ArrowDown' || e.key === 'ArrowUp') &&
      results.length
    ) {
      openDropdown();
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive(Math.min(activeIndex + 1, results.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive(Math.max(activeIndex - 1, 0));
    } else if (e.key === 'Enter') {
      if (activeIndex >= 0 && results[activeIndex]) {
        e.preventDefault();
        selectOrder(results[activeIndex]);
      }
    } else if (e.key === 'Escape') {
      closeDropdown();
    }
  });

  $list.on('mouseenter', '.select2-results__option', function () {
    setActive(parseInt($(this).data('index'), 10));
  });

  $list.on('mousedown', '.select2-results__option', function (e) {
    // mousedown (not click) so it fires before the input's blur/close
    e.preventDefault();
    let index = parseInt($(this).data('index'), 10);
    if (results[index]) selectOrder(results[index]);
  });

  $(document).on('mousedown', function (e) {
    if ($(e.target).closest('#order_lookup_dropdown, #order_lookup').length)
      return;
    closeDropdown();

    // Clicking outside resets the field, not just closes the dropdown.
    if (currentXhr) currentXhr.abort();
    clearTimeout(debounceTimer);
    $input.val('');
    currentTerm = '';
    results = [];
    activeIndex = -1;
  });

  $(window).on('resize scroll', function () {
    if (isOpen) positionDropdown();
  });

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
      e.preventDefault();
      $input.trigger('focus');
    }
  });
});

/* ============================================================
 * SECTION: Hero greeting, clock & date
 * ------------------------------------------------------------
 * Drives the greeting banner under the header video: shows an
 * hour-specific Romanian greeting/icon, and keeps a live clock and
 * date ticking every second.
 * ============================================================ */
(function () {
  // Arrays defined entirely in JS
  const specificGreetings = {
    8: {
      word: 'Motoarele pornite, pregătin cafeaua!',
      icon: 'fa-bolt',
    },
    9: {
      word: 'Să curgă printurile',
      icon: 'fa-print',
    },
    10: {
      word: 'Începem ziua cu idei proaspete',
      icon: 'fa-lightbulb',
    },
    11: {
      word: 'Aproape prânz, menținem ritmul',
      icon: 'fa-clock',
    },
    12: {
      word: 'Poftă bună',
      icon: 'fa-utensils',
    },
    13: {
      word: 'Continuăm cu drag și spor',
      icon: 'fa-battery-full',
    },
    14: {
      word: 'Creativitate la maxim',
      icon: 'fa-lightbulb',
    },
    15: {
      word: 'Lucrăm la detalii, rezultatul contează',
      icon: 'fa-eye',
    },
    16: {
      word: 'Printăm la superlativ',
      icon: 'fa-palette',
    },
    17: {
      word: 'Finalizăm comenzile',
      icon: 'fa-check-double',
    },
  };

  const genericGreeting = {
    word: 'Sistem Color Print online. Bine ai venit',
    icon: 'fa-power-off',
  };

  let dayNames = [
    'Duminică',
    'Luni',
    'Marți',
    'Miercuri',
    'Joi',
    'Vineri',
    'Sâmbătă',
  ];
  let monthNames = [
    'ianuarie',
    'februarie',
    'martie',
    'aprilie',
    'mai',
    'iunie',
    'iulie',
    'august',
    'septembrie',
    'octombrie',
    'noiembrie',
    'decembrie',
  ];

  function getHourlyGreeting(hour) {
    return specificGreetings[hour] || genericGreeting;
  }

  function pad(n) {
    return n.toString().padStart(2, '0');
  }

  function updateHeroGreeting() {
    let now = new Date();

    // Update Clock & Date
    let clockEl = document.getElementById('heroGreetingClock');
    let dateEl = document.getElementById('heroGreetingDate');
    if (clockEl) {
      clockEl.textContent =
        pad(now.getHours()) +
        ':' +
        pad(now.getMinutes()) +
        ':' +
        pad(now.getSeconds());
    }
    if (dateEl) {
      dateEl.textContent =
        dayNames[now.getDay()] +
        ', ' +
        now.getDate() +
        ' ' +
        monthNames[now.getMonth()];
    }

    // Update Greeting Word & Icon
    let hour = now.getHours();
    let greetingData = getHourlyGreeting(hour);
    let wordEl = document.getElementById('heroGreetingWord');
    let iconEl = document.getElementById('heroGreetingIcon');

    if (wordEl && iconEl) {
      // Only update the DOM if the text actually changed to save browser rendering resources
      if (wordEl.textContent !== greetingData.word) {
        wordEl.textContent = greetingData.word;
        iconEl.innerHTML = '<i class="fa-solid ' + greetingData.icon + '"></i>';
      }
    }
  }

  // Call immediately on load to populate the empty HTML elements
  updateHeroGreeting();
  // Then update every second for the clock
  setInterval(updateHeroGreeting, 1000);
})();

/* ============================================================
 * SECTION: WhatsApp quick-send widget
 * ------------------------------------------------------------
 * Opens/closes the WhatsApp modal from the hero "quick actions" bar,
 * validates the phone number, and opens wa.me with the right country
 * prefix (dropdown prefix or manual override) plus number.
 * ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
  const widget = document.getElementById('whatsappWidget');
  const modal = document.getElementById('whatsappModal');
  const closeBtn = document.querySelector('.whatsapp-close-btn');
  const sendBtn = document.getElementById('sendWhatsappBtn');

  if (!widget || !modal || !closeBtn || !sendBtn) {
    return; // quick-send widget only exists on dashboard.php
  }

  widget.addEventListener('click', () => {
    modal.style.display = 'flex'; // match Notes modal behavior
  });

  closeBtn.addEventListener('click', () => {
    modal.style.display = 'none';
  });

  // Send button logic
  sendBtn.addEventListener('click', () => {
    const dropdownPrefix = document.getElementById('countryPrefixSelect').value;
    let manualPrefix = document.getElementById('manualPrefix').value.trim();
    let number = document.getElementById('whatsappNumber').value.trim();

    manualPrefix = manualPrefix.replace(/\D/g, '');
    number = number.replace(/\D/g, '');

    if (number.length < 5) {
      Swal.fire('Eroare', 'Numărul introdus nu este valid.', 'error');
      return;
    }

    const prefix = manualPrefix !== '' ? manualPrefix : dropdownPrefix;
    const fullNumber = prefix + number;

    window.open(`https://wa.me/${fullNumber}`, '_blank');
  });
});

document.addEventListener('DOMContentLoaded', function () {
  const dropdown = document.getElementById('countryPrefixSelect');
  const manual = document.getElementById('manualPrefix');

  if (!dropdown || !manual) return; // prefix controls only exist on dashboard.php

  function updatePrefixUI() {
    if (manual.value.trim() !== '') {
      // Manual prefix is active
      manual.classList.add('prefix-active');
      manual.classList.remove('prefix-inactive');

      dropdown.classList.add('prefix-inactive');
      dropdown.classList.remove('prefix-active');
    } else {
      // Dropdown is active
      dropdown.classList.add('prefix-active');
      dropdown.classList.remove('prefix-inactive');

      manual.classList.add('prefix-inactive');
      manual.classList.remove('prefix-active');
    }
  }

  // Trigger UI update on input
  manual.addEventListener('input', updatePrefixUI);
  dropdown.addEventListener('change', updatePrefixUI);

  // Initial state
  updatePrefixUI();
});

/* ============================================================
 * SECTION: sendSms (shared helper)
 * ------------------------------------------------------------
 * POSTs the "order finished" SMS to send_sms.php. Shared by the
 * view_order.php flow (window.finishOrder) AND the order-preview
 * Tippy popup on the dashboard, so marking an order Terminată
 * from either place sends the same notification.
 * Accepts an optional onSuccess callback (fires only when the
 * request returns HTTP 200).
 * ============================================================ */
window.sendSms = function (clientPhone, orderId, assignedTo, clientName, boss, onSuccess) {
  let xhr = new XMLHttpRequest();
  xhr.open('POST', 'send_sms.php', true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  xhr.onreadystatechange = function () {
    if (xhr.readyState === 4) {
      if (xhr.status === 200) {
        console.log('SMS SENT for order ' + orderId);
        if (typeof onSuccess === 'function') onSuccess();
      } else {
        console.error('Nu s-a putut trimite SMS pentru comanda ' + orderId + ' (status ' + xhr.status + ')');
      }
    }
  };
  xhr.onerror = function () {
    console.error('SMS request error for order ' + orderId);
  };
  xhr.send(
    'to=' +
      encodeURIComponent(clientPhone) +
      '&order_id=' +
      encodeURIComponent(orderId) +
      '&assigned_to=' +
      encodeURIComponent(assignedTo) +
      '&client_name=' +
      encodeURIComponent(clientName) +
      '&boss=' +
      encodeURIComponent(boss),
  );
};

/* ============================================================
 * SECTION: Order preview tooltip (Tippy)
 * ------------------------------------------------------------
 * Attaches a Tippy.js tooltip to every order row that lazily loads
 * order_preview.php and wires up its finish/deliver/print buttons.
 * Since Tippy injects that HTML via innerHTML (which never executes
 * <script> tags), the button handlers have to be bound here, against
 * the live DOM, instead of inside the fetched HTML itself.
 * ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
  // --- ORDER PREVIEW ACTIONS (finish / deliver / print) ---
  // order_preview.php's HTML is injected into the Tippy popup via
  // instance.setContent(), which sets innerHTML — any <script> tag inside
  // that HTML is inert (browsers never execute scripts inserted that way).
  // So the buttons' handlers are bound here instead, directly against the
  // live DOM. This works because Tippy appends its popup to
  // document.body (see appendTo below), not into an iframe.
  function bindOrderPreviewActions(instance, orderId) {
    const root = instance.popper;
    if (!root) return;

    const finishBtn = root.querySelector('#finishBtn');
    const deliverBtn = root.querySelector('#deliverBtn');
    const printBtn = root.querySelector('#printBtn');

    if (finishBtn) {
      finishBtn.onclick = function () {
        Swal.fire({
          title: 'Marcați comanda #' + orderId + ' ca terminată?',
          icon: 'question',
          showCancelButton: true,
          confirmButtonText: 'Da',
          cancelButtonText: 'Anulează',
        }).then((result) => {
          if (result.isConfirmed) {
            quickUpdateOrderStatus(orderId, 'completed', {}, instance, function () {
              // Mirror view_order.php's finish flow: after the order is
              // marked completed, notify the client via SMS.
              const content = root.querySelector('.order-preview-content');
              if (content) {
                window.sendSms(
                  content.dataset.clientPhone || '',
                  orderId,
                  content.dataset.assignedTo || '',
                  content.dataset.clientName || '',
                  content.dataset.boss || '',
                );
              }
            });
          }
        });
      };
    }

    if (deliverBtn) {
      deliverBtn.onclick = function () {
        Swal.fire({
          title: 'Marcați comanda #' + orderId + ' ca livrată?',
          icon: 'question',
          showCancelButton: true,
          confirmButtonText: 'Da',
          cancelButtonText: 'Anulează',
        }).then((result) => {
          if (result.isConfirmed) {
            const currentDate = new Date()
              .toISOString()
              .slice(0, 19)
              .replace('T', ' ');
            quickUpdateOrderStatus(
              orderId,
              'delivered',
              {
                delivery_date: currentDate,
              },
              instance,
            );
          }
        });
      };
    }

    if (printBtn) {
      printBtn.onclick = function () {
        printOrderTicket(orderId);
      };
    }
  }

  // Shared by finish/deliver above — same endpoint & param shape
  // view_order.php already uses for these actions.
  function quickUpdateOrderStatus(orderId, status, extra, instance, onSuccess) {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'update_order_status.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

    let body =
      'order_id=' +
      encodeURIComponent(orderId) +
      '&status=' +
      encodeURIComponent(status);
    Object.keys(extra).forEach((key) => {
      body += '&' + key + '=' + encodeURIComponent(extra[key]);
    });

    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;

      if (xhr.status === 200) {
        Toast.fire({
          icon: 'success',
          title: 'Comandă actualizată',
        });
        if (instance) instance.hide();
        if (typeof window.quietRefresh === 'function') window.quietRefresh();
        if (typeof onSuccess === 'function') onSuccess();
      } else {
        Toast.fire({
          icon: 'error',
          title: 'Nu s-a putut actualiza comanda',
        });
      }
    };
    xhr.onerror = function () {
      Toast.fire({
        icon: 'error',
        title: 'Nu s-a putut actualiza comanda',
      });
    };
    xhr.send(body);
  }

  // The "ticket" is the real view_order.php page — it already has the
  // #printArea / @media print rules that hide everything except the
  // order contents. Load it off-screen in a hidden iframe, then fire the
  // browser print dialog against THAT window, so the tippy preview's
  // print button produces the same ticket as the "Print Order" button
  // on the full order page, without navigating the dashboard away.
  function printOrderTicket(orderId) {
    const existing = document.getElementById('ticketPrintFrame');
    if (existing) existing.remove();

    const iframe = document.createElement('iframe');
    iframe.id = 'ticketPrintFrame';
    // Not display:none — some browsers skip printing content from
    // display:none frames. Kept in-flow but off-screen and invisible.
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    iframe.style.visibility = 'hidden';

    let cleaned = false;
    const cleanup = () => {
      if (cleaned) return;
      cleaned = true;
      setTimeout(() => iframe.remove(), 500);
    };

    iframe.onload = function () {
      try {
        iframe.contentWindow.focus();
        iframe.contentWindow.addEventListener('afterprint', cleanup);
        iframe.contentWindow.print();
      } catch (err) {
        console.error('Nu s-a putut printa comanda:', err);
        Toast.fire({
          icon: 'error',
          title: 'Nu s-a putut printa comanda',
        });
        cleanup();
      }
      // Safety net in case 'afterprint' never fires (e.g. print dialog
      // was cancelled in a way some browsers don't report).
      setTimeout(cleanup, 15000);
    };

    iframe.src = 'view_order.php?order_id=' + encodeURIComponent(orderId);
    document.body.appendChild(iframe);
  }

  // Wrap Tippy init in a global function
  window.initTippy = function () {
    tippy('.order-row', {
      allowHTML: true,
      interactive: true,
      theme: 'order-preview',
      placement: 'top',
      maxWidth: 350,
      delay: [200, 0],
      animation: 'shift-away',
      offset: [0, 10],
      boundary: 'window', // Keeps the tooltip strictly within the viewport
      appendTo: document.body,

      onShow(instance) {
        const reference = instance.reference;
        const id = reference.getAttribute('data-order-id');

        // --- DYNAMIC THEMING -------------------------------------------------
        // Each order row carries a heavy-theme class (theme-yellow / cyan /
        // magenta / green / key). Read it off the hovered <tr> so the preview
        // adopts that row's color: it is forwarded to order_preview.php as
        // &theme= (server-side accent) AND mirrored onto the tippy box right
        // away so the tooltip is already tinted while content loads.
        const themeMatch = reference.className.match(
          /theme-(yellow|cyan|magenta|green|key)/,
        );
        const theme = themeMatch ? themeMatch[1] : 'yellow';

        const previewBox =
          instance.popper && instance.popper.querySelector('.tippy-box');
        if (previewBox) {
          previewBox.classList.remove(
            'theme-yellow',
            'theme-cyan',
            'theme-magenta',
            'theme-green',
            'theme-key',
          );
          previewBox.classList.add('theme-' + theme);
        }

        // Placeholder sized close to the real preview (maxWidth is 350)
        // so the popup barely resizes when the fetched HTML arrives —
        // prevents any visible slide/jump on load.
        instance.setContent(
          '<div style="width: 300px; min-height: 110px; display: flex; align-items: center; justify-content: center;">Loading...</div>',
        );

        fetch(
          'order_preview.php?id=' +
            encodeURIComponent(id) +
            '&theme=' +
            encodeURIComponent(theme),
        )
          .then((res) => res.text())
          .then((html) => {
            instance.setContent(html);
            bindOrderPreviewActions(instance, id);
          })
          .catch(() => {
            instance.setContent('Eroare la încărcare');
          });
      },
    });
  };

  // Initialize on first load — Tippy is only included on dashboard.php
  if (typeof tippy === 'function') window.initTippy();
});

/* ============================================================
 * SECTION: Filters, sorting & pagination (quiet refresh wiring)
 * ------------------------------------------------------------
 * Builds dashboard.php URLs from the current filter form + sort
 * arrows + pagination links, and runs them through quietRefresh()
 * (see the slider section above) instead of a full navigation, with
 * a small loading state on the filters toolbar and a confirmation
 * toast when done.
 * ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
  // Wait for Select2 to finish initializing
  setTimeout(() => {
    const arrows = document.querySelectorAll('.sort-arrows .arrow');
    const hiddenInput = document.getElementById('sort_order');
    const filtersWrapper = document.querySelector('.filters');
    const filterForm = document.querySelector('.filters form');
    const resetBtn = document.getElementById('resetFiltersBtn');

    if (!filterForm || !hiddenInput) return; // filter toolbar only exists on dashboard.php

    // Stat-card "smart" status buckets have no <option> in the #status_filter
    // <select>, so they can't be represented by the form alone. BuildFilterUrl()
    // reads the form, so without this we'd LOSE the filter when sorting/filtering
    // (the select is empty for smart buckets and the param drops out). Keep the
    // current smart filter here so buildFilterUrl() re-applies it.
    const SMART_STATUS_FILTERS = ['overdue', 'deliver_today', 'delivered_today'];
    let smartFilterStatus = '';

    // If the page loaded with a smart filter already active (e.g. ?status_filter=
    // deliver_today), pick it up so a subsequent sort keeps it.
    const activeCard = document.querySelector(
      '.stat-card.stat-filter-active[data-status-filter]',
    );
    if (activeCard) smartFilterStatus = activeCard.dataset.statusFilter;

    // Highlight active arrow on load
    arrows.forEach((a) => {
      if (a.dataset.value === hiddenInput.value) {
        a.classList.add('active');
      }
    });

    // Reads the filter form's current state into a dashboard.php URL.
    // overrides lets a caller add/replace/remove a param (e.g. page).
    function buildFilterUrl(overrides = {}) {
      const formData = new FormData(filterForm);
      const params = new URLSearchParams();

      for (const [key, value] of formData.entries()) {
        if (value !== '') params.append(key, value);
      }

      // Smart stat-card filters can't live in the <select>, so re-apply the
      // currently active one unless the caller explicitly overrides it.
      if (smartFilterStatus !== '') {
        params.set('status_filter', smartFilterStatus);
      }

      Object.entries(overrides).forEach(([key, value]) => {
        if (value === null || value === '') {
          params.delete(key);
        } else {
          params.set(key, value);
        }
      });

      const query = params.toString();
      return 'dashboard.php' + (query ? '?' + query : '');
    }

    // Runs the quiet refresh against a given URL, with a small loading
    // cue on the toolbar itself since a network round-trip — even a fast
    // one — isn't literally instant.
    function goQuietly(url) {
      if (filtersWrapper) filtersWrapper.classList.add('is-loading');

      window
        .quietRefresh(url, {
          resetForm: false,
        })
        .finally(() => {
          if (filtersWrapper) filtersWrapper.classList.remove('is-loading');

          // 4. Afișăm succesul când a terminat, apoi îl închidem
          Swal.fire({
            toast: 'true',
            icon: 'success',
            title: 'Filtru actualizat',
            position: 'center',
            width: 'auto',
            showConfirmButton: false,
            timer: 750,
            backdrop: false,
          });
        });
    }

    arrows.forEach((arrow) => {
      arrow.addEventListener('click', function () {
        hiddenInput.value = this.dataset.value;

        arrows.forEach((a) => a.classList.remove('active'));
        this.classList.add('active');

        goQuietly(buildFilterUrl());
      });
    });

    // "Aplică filtre" — stays type="submit" so a real GET submission is
    // still the fallback if JS fails to load for any reason.
    filterForm.addEventListener('submit', function (e) {
      e.preventDefault();
      goQuietly(buildFilterUrl());
    });

    // Apply filters automatically on Select2 user selections or clears
    $('#status_filter, #assigned_filter, #client_filter').on(
      'select2:select select2:clear',
      function () {
        // Keep the stat-card highlight in step when the status is changed via
        // the dropdown (assigned/completed map to the matching card).
        if (this.id === 'status_filter') {
          // A dropdown value is representable in the form — no longer a "smart"
          // filter, so stop re-applying the stale smart bucket on sort.
          smartFilterStatus = '';
          syncStatCardHighlight(this.value);
        }
        goQuietly(buildFilterUrl());
      },
    );

    // "Resetează filtre" — clear the visible controls, then navigate quietly
    if (resetBtn) {
      resetBtn.addEventListener('click', function (e) {
        e.preventDefault();

        $('#status_filter, #assigned_filter').val('').trigger('change');
        $('#client_filter').val(null).trigger('change');

        hiddenInput.value = 'ASC';
        arrows.forEach((a) => a.classList.remove('active'));
        arrows.forEach((a) => {
          if (a.dataset.value === 'ASC') a.classList.add('active');
        });

        smartFilterStatus = '';
        clearStatCardActive();
        goQuietly('dashboard.php');
      });
    }

    // --- STAT CARDS AS FILTER SHORTCUTS ---------------------------------
    // Each .stat-card carries a data-status-filter. Clicking (or focusing +
    // Enter/Space) toggles that status bucket on the table via the same quiet
    // refresh used by the toolbar. Clicking the active card again clears it.

    function clearStatCardActive() {
      document.querySelectorAll('.stat-card').forEach((card) => {
        card.classList.remove('stat-filter-active');
        card.setAttribute('aria-pressed', 'false');
      });
    }

    function syncStatCardHighlight(statusValue) {
      document.querySelectorAll('.stat-card[data-status-filter]').forEach(
        (card) => {
          const active =
            statusValue !== '' && card.dataset.statusFilter === statusValue;
          card.classList.toggle('stat-filter-active', active);
          card.setAttribute('aria-pressed', active ? 'true' : 'false');
        },
      );
    }

    document
      .querySelectorAll('.stat-card[data-status-filter]')
      .forEach((card) => {
        function applyCardFilter() {
          const value = card.dataset.statusFilter;
          const wasActive = card.classList.contains('stat-filter-active');
          const next = wasActive ? '' : value; // click active card → clear

          // Keep the toolbar's status <select> honest: values it has an option
          // for are shown, smart buckets (overdue/deliver_today/...) have no
          // <option> so fall back to the neutral "Active" entry.
          const statusSelect = document.getElementById('status_filter');
          if (statusSelect) {
            const knownOptions = [
              '',
              'assigned',
              'completed',
              'delivered',
              'cancelled',
            ];
            statusSelect.value = knownOptions.includes(next) ? next : '';
            // Repaint the Select2 widget WITHOUT firing our select2 refresh
            // handler (that would cause a second, redundant table fetch).
            if (typeof jQuery !== 'undefined') {
              $(statusSelect).trigger('change');
            }
          }

          syncStatCardHighlight(next === '' ? '' : value);
          // Remember the active smart bucket so a later sort/filter keeps it.
          smartFilterStatus = SMART_STATUS_FILTERS.includes(next) ? next : '';
          goQuietly(buildFilterUrl({ status_filter: next, page: 1 }));
        }

        card.addEventListener('click', applyCardFilter);
        card.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            applyCardFilter();
          }
        });
      });

    // Pagination links live inside the AJAX-swapped .pagination block, so
    // they're recreated on every refresh — rebind after each one via the
    // hook quietRefresh() calls (window.bindPaginationClickEvents).
    function bindPaginationClickEvents() {
      document.querySelectorAll('.pagination a').forEach((link) => {
        link.addEventListener('click', function (e) {
          e.preventDefault();
          goQuietly(this.getAttribute('href'));
        });
      });
    }
    window.bindPaginationClickEvents = bindPaginationClickEvents;
    bindPaginationClickEvents();
  }, 200); // ← gives Select2 time to initialize
});

/* ============================================================
 * SECTION: Client required-field toggle (add order form)
 * ------------------------------------------------------------
 * Keeps the native `required` attribute on the "new client"
 * name/phone inputs in sync with whether an existing client is
 * selected via Select2, so the browser doesn't block submission for
 * fields that are hidden because a client was already picked.
 * ============================================================ */
// Call this after Select2 is initialized
function syncClientRequiredState() {
  const hasClient = !!$('#client_id').val(); // Select2 value
  if (hasClient) {
    // A client is selected: remove required so browser won't block submit
    $('#client_name, #client_phone').prop('required', false);
    // Hide new-client fields for clarity (animated via CSS class)
    $('#new_client_fields').addClass('collapsed');
  } else {
    // No client selected: enforce required again
    $('#client_name, #client_phone').prop('required', true);
    $('#new_client_fields').removeClass('collapsed');
  }
}

// Run on page load — but only where #client_id exists (dashboard.php).
// view_order.php loads this file too but has no client picker; running
// syncClientRequiredState there would wrongly flip #client_name and
// #client_phone to required on view_order.php's hidden inputs.
$(document).ready(function () {
  if (!$('#client_id').length) return;
  syncClientRequiredState();
  // Update when Select2 changes or is cleared
  $('#client_id').on(
    'select2:select select2:unselect change',
    syncClientRequiredState,
  );
});

/* ============================================================
 * SECTION: View Order Page (view_order.php)
 * ------------------------------------------------------------
 * All JavaScript that used to live in inline <script> blocks
 * inside view_order.php. PHP-generated values are read from
 * the #viewOrderDataBridge data attributes emitted by the
 * server, so no inline PHP appears in this file.
 *
 * Guarded: everything is a no-op unless the data-bridge element
 * exists (i.e. we are on view_order.php).
 *
 * The Toast mixin is the same one defined earlier in this file
 * (~line 300), so it is reused here rather than redefined.
 * ============================================================ */

(function () {
  // ---- Guard: only run on view_order.php ----
  let bridge = document.getElementById('viewOrderDataBridge');
  if (!bridge) return;

  // ---- PHP → JS value bridge ----
  let currentOrderId = parseInt(
    bridge.getAttribute('data-order-id') || '0',
    10,
  );
  let assignedTo = bridge.getAttribute('data-assigned-to') || '';
  let clientName = bridge.getAttribute('data-client-name') || '';
  let boss = bridge.getAttribute('data-boss') || '';
  let clientPhone = bridge.getAttribute('data-client-phone') || '';
  let waLink = bridge.getAttribute('data-wa-link') || '';

  // ---- SLA data (replaces inline const SLA) ----
  let SLA = {
    dueDateIso: bridge.getAttribute('data-due-date-iso') || null,
    serverNowIso: bridge.getAttribute('data-server-now-iso') || null,
    warnThresholdSeconds: 24 * 3600,
  };

  // ---- Flash messages (replaces inline PHP flash scripts) ----
  $(function () {
    let fs = bridge.getAttribute('data-flash-success');
    let fe = bridge.getAttribute('data-flash-error');
    if (fs) Toast.fire({ icon: 'success', title: fs });
    if (fe) Toast.fire({ icon: 'success', title: fe });
  });

  // ============================================================
  // Global functions — called from inline onclick attributes
  // in view_order.php's HTML, so they must live on window.
  // ============================================================

  window.editOrderDetails = function () {
    const suplText = document.getElementById('detalii_suplimentare_text');
    if (suplText) suplText.style.display = 'none';

    const avansText = document.getElementById('avans_text');
    if (avansText) avansText.style.display = 'none';

    // Show inputs
    const suplEdit = document.getElementById('detalii_suplimentare_edit');
    if (suplEdit) suplEdit.style.display = 'block';

    const avansEdit = document.getElementById('avans_edit');
    if (avansEdit) avansEdit.style.display = 'inline';

    // Toggle buttons
    const btnEdit = document.querySelector(
      'button[onclick="editOrderDetails()"]',
    );
    const btnSave = document.querySelector(
      'button[onclick="saveOrderDetails()"]',
    );
    if (btnEdit) btnEdit.style.display = 'none';
    if (btnSave) btnSave.style.display = 'inline';
  };

  window.saveOrderDetails = function () {
    const detaliiSuplimentare = $('#detalii_suplimentare_edit').val() || '';
    const avans = $('#avans_edit').val() || '';
    const orderId = currentOrderId;

    $.ajax({
      url: 'update_order_details.php',
      method: 'POST',
      data: {
        order_id: orderId,
        detalii_suplimentare: detaliiSuplimentare,
        avans: avans,
      },
      success: function () {
        $('#detalii_suplimentare_text').text(detaliiSuplimentare).show();
        $('#detalii_suplimentare_edit').hide();
        $('#avans_text').text(avans).show();
        $('#avans_edit').hide();
        $('button[onclick="editOrderDetails()"]').show();
        $('button[onclick="saveOrderDetails()"]').hide();
        Toast.fire({
          icon: 'success',
          title: 'Detaliile comenzii au fost salvate!',
        });
        setTimeout(() => {
          location.reload();
        }, 1500);
      },
      error: function (xhr) {
        Swal.fire({
          icon: 'error',
          title: 'Eroare la salete',
          text: xhr.responseText || 'Status: ' + xhr.status,
          position: 'center',
        });
      },
    });
  };

  window.togglePin = function (orderId, pinState) {
    $.post('toggle_pin.php', { order_id: orderId, is_pinned: pinState })
      .done(() => {
        Toast.fire({ icon: 'success', title: 'Pin actualizat 📌' });
        setTimeout(() => location.reload(), 1200);
      })
      .fail((xhr) => {
        Swal.fire({
          icon: 'error',
          title: 'Eroare',
          text: xhr.responseText || 'Nu s-a putut actualiza pin-ul.',
          position: 'center',
        });
      });
  };

  window.finishOrder = function () {
    let orderId = currentOrderId;

    let xhr = new XMLHttpRequest();
    xhr.open('POST', 'update_order_status.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

    xhr.onreadystatechange = function () {
      if (xhr.readyState === 4) {
        if (xhr.status === 200) {
          $('#step-completed-circle')
            .css('background', '#2ecc71')
            .css('color', '#fff')
            .html('<i class="fa-solid fa-flag"></i>');
          sendSMS(clientPhone, orderId, assignedTo, clientName, boss);
          let button = document.getElementById('finishButton');
          if (button) {
            console.log('Butonul a fost găsit și va fi șters.');
            button.parentNode.removeChild(button);
          } else {
            console.log('Butonul nu a fost găsit.');
          }
        } else {
          console.error('Cererea a eșuat cu status:', xhr.status);
          Swal.fire({
            icon: 'error',
            title: 'Eroare',
            text: 'Finalizarea comenzii a eșuat.',
            position: 'center',
          });
        }
      }
    };

    xhr.onerror = function () {
      console.error('Eroare la cererea AJAX');
      Swal.fire({
        icon: 'error',
        title: 'Eroare',
        text: 'Finalizarea comenzii a eșuat.',
        position: 'center',
      });
    };

    xhr.send('order_id=' + encodeURIComponent(orderId) + '&status=completed');
  };

  // sendSMS is called from finishOrder() — local to the IIFE.
  // It delegates the actual request to the shared window.sendSms helper
  // (same one the order-preview Tippy popup uses), then shows the success
  // modal only after send_sms.php answered with HTTP 200.
  function sendSMS(clientPhone, orderId, assignedTo, clientName, boss) {
    window.sendSms(clientPhone, orderId, assignedTo, clientName, boss, function () {
      Swal.fire({
        icon: 'success',
        title: 'Felicitări!',
        text: 'Comanda a fost terminată cu succes 🎉',
        position: 'center',
        showConfirmButton: false,
        timer: 2000,
        timerProgressBar: true,
      }).then(() => {
        console.log('SMS SENT');
      });
    });
  }

  window.deliverOrder = function () {
    let orderId = currentOrderId;
    let currentDate = new Date().toISOString().slice(0, 19).replace('T', ' ');

    let xhr = new XMLHttpRequest();
    xhr.open('POST', 'update_order_status.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

    xhr.onreadystatechange = function () {
      if (xhr.readyState == 4 && xhr.status == 200) {
        $('#step-completed-circle')
          .css('background', '#2ecc71')
          .css('color', '#fff')
          .html('<i class="fa-solid fa-flag"></i>');
        $('#step-delivered-circle')
          .css('background', 'linear-gradient(135deg, #3498db, #2ecc71)')
          .css('color', '#fff')
          .html('<i class="fa-solid fa-gift"></i>');
        Swal.fire({
          icon: 'success',
          title: 'Comanda livrată',
          text: xhr.responseText,
          position: 'center',
          showConfirmButton: false,
          timer: 2000,
        }).then(() => {
          console.log('Comanda Livrată');
        });
      } else if (xhr.readyState == 4) {
        Swal.fire({
          icon: 'error',
          title: 'Eroare',
          text: 'Nu s-a putut marca comanda ca livrată.',
          position: 'center',
        });
      }
    };
    xhr.send(
      'order_id=' +
        orderId +
        '&status=delivered&delivery_date=' +
        encodeURIComponent(currentDate),
    );
    let button = document.getElementById('deliverButton');
    button.parentNode.removeChild(button);
  };

  window.cancelOrder = function () {
    let orderId = currentOrderId;
    Swal.fire({
      title: 'Anulezi comanda?',
      text: 'Sigur vrei să anulezi comanda #' + orderId + '?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Da, anulează',
      cancelButtonText: 'Renunță',
    }).then((result) => {
      if (!result.isConfirmed) return;
      let xhr = new XMLHttpRequest();
      xhr.open('POST', 'cancel_order.php', true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.onreadystatechange = function () {
        if (xhr.readyState == 4 && xhr.status == 200) {
          $('#step-inprogress-circle')
            .css('background', '#ddd')
            .css('color', '#888')
            .text('2');
          $('#step-completed-circle')
            .css('background', '#ddd')
            .css('color', '#888')
            .text('3');
          $('#step-delivered-circle')
            .css('background', '#ddd')
            .css('color', '#888')
            .text('4');
          Swal.fire({
            icon: 'success',
            title: 'Comanda anulată',
            text: xhr.responseText,
            position: 'center',
            showConfirmButton: false,
            timer: 2000,
          }).then(() => window.location.reload());
        } else if (xhr.readyState == 4) {
          Swal.fire({
            icon: 'error',
            title: 'Eroare',
            text: 'Nu s-a putut anula comanda.',
            position: 'center',
          });
        }
      };
      xhr.send('order_id=' + orderId);
    });
  };

  window.printOrder = function () {
    window.print();
  };

  // Fills the articles for orders
  function loadOrderArticles(orderId) {
    const $table = $('#bonTable');
    const $tbody = $('#bonTableBody');
    const emptyNote = document.getElementById('emptyNote');

    if (emptyNote) {
      emptyNote.style.display = 'none';
    }

    $.getJSON(
      'fetch_order_articles.php?order_id=' + encodeURIComponent(orderId),
      (data) => {
        $tbody.empty();

        if (!data.length) {
          if (emptyNote) {
            emptyNote.style.display = 'block';
          }
          $table.addClass('no-print');
          $('#totalPrice').text('0.00 lei');
          return;
        }

        $table.removeClass('no-print');
        let total = 0;
        data.forEach((row) => {
          const qty = Number(row.quantity);
          const unit = Number(row.price_per_unit);
          total += qty * unit;

          $tbody.append(`
    <tr data-id="${row.id}">
        <td>${row.name}</td>
        <td>${qty}</td>
        <td>${unit.toFixed(2)}</td>
        <td><button class="removeArticle">✖</button></td>
    </tr>
    `);
        });
        const avans = parseFloat($('#avans_text').text()) || 0;
        $('#totalPrice').text((total - avans).toFixed(2) + ' lei');
      },
    );
  }

  window.toggleComandaLucru = function () {
    let comandaLucruElement = document.getElementById('comandaLucruElement');
    if (comandaLucruElement) {
      comandaLucruElement.parentNode.removeChild(comandaLucruElement);
    } else {
      let h2Element = document.createElement('h2');
      h2Element.id = 'comandaLucruElement';
      h2Element.textContent = 'Comandă în lucru';
      document.querySelector('h2').insertAdjacentElement('afterend', h2Element);
    }
  };

  window.openWhatsAppWithMessage = function (rawPhone, rawMessage) {
    const countryCode = '+4';
    const digitsOnly = String(rawPhone || '').replace(/\D/g, '');
    const waNumber = countryCode + digitsOnly;

    if (!digitsOnly || digitsOnly.length < 6) {
      console.error('Invalid phone after normalization:', waNumber);
      Swal.fire({
        icon: 'error',
        title: 'Număr invalid',
        text: 'Numărul clientului nu este valid.',
      });
      return;
    }

    const message = String(rawMessage || '').trim();
    if (!message) {
      Swal.fire({
        icon: 'warning',
        title: 'Mesaj gol',
        text: 'Completează mesajul înainte de a trimite.',
      });
      return;
    }
    const encoded = encodeURIComponent(message);
    const waUrl = `https://wa.me/${encodeURIComponent(waNumber)}?text=${encoded}`;
    const newWindow = window.open(waUrl, '_blank');
    if (!newWindow) {
      window.location.href = waUrl;
    }
    console.log('WhatsApp URL:', waUrl);
  };

  // ============================================================
  // Event handlers and initialization
  // ============================================================

  $(function () {
    // --- Load articles on page load ---
    loadOrderArticles(currentOrderId);

    // --- Add article form via AJAX (consolidated) ---
    $(document).on('submit', '#addArticleForm', function (e) {
      e.preventDefault();
      const form = this;
      $.ajax({
        url: $(form).attr('action'),
        method: 'POST',
        data: $(form).serialize(),
        success: function (resp) {
          loadOrderArticles(currentOrderId);
          Toast.fire({ icon: 'success', title: 'Articolul a fost adăugat!' });
          $('#articleSelect').val(null).trigger('change');
          $('#quantity').val(1);
          $('#price').val('');
        },
        error: function (xhr) {
          Swal.fire({
            icon: 'error',
            title: 'Eroare',
            text: xhr.responseText || 'Nu s-a putut adăuga articolul.',
            position: 'center',
          });
        },
      });
    });

    // --- Remove article ---
    $(document).on('click', '.removeArticle', function () {
      const row = $(this).closest('tr');
      const id = row.data('id');
      if (!id) {
        Swal.fire({
          icon: 'error',
          title: 'Eroare',
          text: 'ID-ul articolului lipsește, nu se poate șterge.',
          position: 'center',
        });
        return;
      }
      Swal.fire({
        title: 'Ștergi articolul?',
        text: 'Această acțiune nu poate fi anulată.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Șterge',
        cancelButtonText: 'Renunță',
      }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('delete_article.php', { id })
          .done(() => {
            loadOrderArticles(currentOrderId);
            Toast.fire({
              icon: 'success',
              title: 'Articolul a fost șters!',
            });
          })
          .fail((xhr) => {
            Swal.fire({
              icon: 'error',
              title: 'Eroare',
              text: xhr.responseText || 'Nu s-a putut șterge articolul.',
              position: 'center',
            });
          });
      });
    });

    // --- Date picker (new_due_date_select) ---
    const dateSelect = document.getElementById('new_due_date_select');
    if (dateSelect) {
      const today = new Date();
      const daysToGenerate = 365;
      for (let i = 0; i < daysToGenerate; i++) {
        const d = new Date();
        d.setDate(today.getDate() + i);
        if (d.getDay() === 0) continue; // Skip Sundays
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const label = d.toLocaleDateString('ro-RO', {
          weekday: 'short',
          day: 'numeric',
          month: 'short',
          year: 'numeric',
        });
        const option = new Option(label, `${year}-${month}-${day}`);
        if (i === 0) option.selected = true;
        dateSelect.add(option);
      }
      $('#new_due_date_select').select2({
        dropdownAutoWidth: true,
        width: 'auto',
        placeholder: 'Selectează data',
      });
    }

    // --- Toggle achitat (paid) button ---
    $('#toggleAchitatButton').on('click', function () {
      const orderId = $(this).data('order-id');
      const currentState = $(this).data('current-state');
      const newState = currentState === 1 ? 0 : 1;
      const $container = $('#achitatContainer-' + orderId);
      $.ajax({
        url: 'update_achitat.php',
        method: 'POST',
        data: { order_id: orderId, is_achitat: newState },
        success: function () {
          if (newState === 1) {
            const $badge = $(
              '<h2 class="achitatBadge">Comandă achitată</h2>',
            ).hide();
            $container.append($badge);
            $badge.fadeIn(400);
            Toast.fire({
              icon: 'success',
              title:
                'Comanda a fost achitată <i class="fa-solid fa-sack-dollar"></i>',
            });
          } else {
            $container.find('.achitatBadge').fadeOut(400, function () {
              $(this).remove();
            });
          }
          $('#toggleAchitatButton')
            .data('current-state', newState)
            .html(
              newState === 1
                ? '<i class="fa-solid fa-ban"></i> Neachitat'
                : '<i class="fa-solid fa-sack-dollar"></i> Comandă achitată',
            );
        },
        error: function (xhr) {
          Swal.fire({
            icon: 'error',
            title: 'Eroare',
            text: xhr.responseText || 'Nu s-a putut actualiza starea comenzii.',
            position: 'center',
          });
        },
      });
    });

    // --- Select2 initialization (filters) ---
    $(
      '#status_filter, #assigned_filter, #category_filter, #sort_order, #assigned_to, #category_id',
    ).select2({
      dropdownAutoWidth: true,
      width: 'auto',
    });

    // --- Article Select2 with AJAX ---
    $('#articleSelect').select2({
      tags: true,
      ajax: {
        url: 'fetch_articles.php',
        dataType: 'json',
        processResults: function (data) {
          return {
            results: data.map((item) => ({
              id: item.id,
              text: `${item.name} (${item.price} lei)`,
              price: item.price,
            })),
          };
        },
      },
    });

    // --- Autofill price for existing items ---
    $('#articleSelect').on('select2:select', function (e) {
      const data = e.params.data;
      if (data.price !== undefined) {
        $('#price').val(data.price);
      } else {
        $('#price').val('');
      }
    });

    // --- Delete attachment ---
    $(document).on('click', '.deleteAttachment', function () {
      const id = $(this).data('id');
      Swal.fire({
        title: 'Ștergi fișierul?',
        text: 'Această acțiune nu poate fi anulată.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Șterge',
        cancelButtonText: 'Renunță',
      }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('delete_attachment.php', { id })
          .done((resp) => {
            $('#attachment-' + id).remove();
            Toast.fire({
              icon: 'success',
              title: 'Fișierul a fost șters.',
            });
          })
          .fail((xhr) => {
            Toast.fire({
              icon: 'error',
              title: xhr.responseText || 'Nu s-a putut șterge fișierul.',
            });
          });
      });
    });

    // --- Template message modal ---
    $('#templateMsgWidget').on('click', function () {
      $('#templateMsgModal').fadeIn(150).css('display', 'flex');
    });
    $('#closeTemplateMsg').on('click', function () {
      $('#templateMsgModal').fadeOut(150);
    });
    $(window).on('click', function (e) {
      if (e.target.id === 'templateMsgModal') {
        $('#templateMsgModal').fadeOut(150);
      }
    });
    $('#templateSelect').on('change', function () {
      let text = $(this).val();
      if (!text) return;
      text = text
        .replace('{{client}}', clientName)
        .replace('{{order}}', currentOrderId);
      $('#templateMessage').val(text);
    });
    $('#sendTemplateMsgBtn').on('click', function () {
      const msg = $('#templateMessage').val().trim();
      if (!msg) {
        Swal.fire({
          icon: 'warning',
          title: 'Mesaj gol',
          text: 'Completează mesajul înainte de a trimite.',
        });
        return;
      }
      const encoded = encodeURIComponent(msg);
      const separator = waLink.includes('?') ? '&' : '?';
      const url = `${waLink}${separator}text=${encoded}`;
      const newWindow = window.open(url, '_blank');
      if (!newWindow) {
        window.location.href = url;
      }
      $('#templateMsgModal').fadeOut(150);
    });

    // --- Update default price button ---
    const priceBtn = document.getElementById('updateDefaultPriceBtn');
    const priceInput = document.getElementById('price');
    const artSelect = document.getElementById('articleSelect');
    if (priceBtn) {
      priceBtn.addEventListener('click', function () {
        const articleId = artSelect.value;
        const newPrice = priceInput.value.trim();
        if (!articleId || isNaN(Number(articleId))) {
          Swal.fire({
            icon: 'warning',
            title: 'Atenție',
            text: 'Selectează un articol existent înainte de a actualiza prețul.',
          });
          return;
        }
        if (newPrice === '' || isNaN(Number(newPrice))) {
          Swal.fire({
            icon: 'warning',
            title: 'Atenție',
            text: 'Introdu un preț numeric valid.',
          });
          return;
        }
        fetch('update_default_price.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body:
            'article_id=' +
            encodeURIComponent(articleId) +
            '&price=' +
            encodeURIComponent(newPrice),
        })
          .then((res) => res.json())
          .then((data) => {
            if (data && data.success) {
              Toast.fire({
                icon: 'success',
                title: 'Prețul implicit a fost actualizat.',
              });
              const selected = $('#articleSelect').select2('data')[0];
              if (selected && selected.name) {
                selected.price = parseFloat(newPrice);
                selected.text = `${selected.name} (${selected.price.toFixed(2)} lei)`;
                $('#articleSelect').trigger('change.select2');
              }
            } else {
              Swal.fire({
                icon: 'error',
                title: 'Eroare',
                text:
                  data && data.error
                    ? data.error
                    : 'Nu s-a putut actualiza prețul.',
              });
            }
          })
          .catch(() =>
            Swal.fire({
              icon: 'error',
              title: 'Eroare de rețea',
              text: 'Nu s-a putut actualiza prețul.',
            }),
          );
      });
    }
  });

  // --- beforeprint / Ctrl+P handler ---
  window.addEventListener('beforeprint', () => {
    const table = document.getElementById('bonTable');
    const hasRows = table.querySelectorAll('tbody tr').length > 0;
    if (!hasRows) {
      table.classList.add('no-print');
    } else {
      table.classList.remove('no-print');
    }
  });
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
      e.preventDefault();
      const printBtn =
        document.querySelector('#printBtn') ||
        document.querySelector('.print-button');
      if (printBtn) {
        printBtn.click();
      } else {
        window.print();
      }
    }
  });

  // --- Dropzone configuration ---
  Dropzone.options.orderDropzone = {
    paramName: 'file',
    maxFilesize: 1024, // MB
    acceptedFiles: null,
    dictDefaultMessage: 'Adaugă fișiere',
    dictFallbackMessage: 'Browserul dvs. nu suportă încărcarea',
    dictFileTooBig:
      'Fișierul este prea mare ({{filesize}}MiB). Dimensiunea maximă: {{maxFilesize}}MiB.',
    dictInvalidFileType: 'Nu puteți încărca fișiere de acest tip.',
    dictResponseError: 'Serverul a răspuns cu codul {{statusCode}}.',
    dictCancelUpload: 'Anulează încărcarea',
    dictRemoveFile: 'Șterge fișierul',
    dictMaxFilesExceeded: 'Nu puteți încărca mai multe fișiere.',
    init: function () {
      this.on('success', function (file, response) {
        console.log('Uploaded:', response);
      });
      this.on('queuecomplete', function () {
        Toast.fire({
          icon: 'success',
          title: 'Toate fișierele au fost adăugate cu succes!',
        }).then(() => {
          window.location.reload();
        });
      });
    },
  };

  // ============================================================
  // SLA Countdown
  // Reads dueDateIso and serverNowIso from the SLA data object
  // (populated from the #viewOrderDataBridge data attributes).
  // Updates the #slaTimer element every second and resyncs the
  // server clock every 60s via server_time.php.
  // ============================================================

  (function () {
    let dueIso = SLA.dueDateIso;
    let serverNowIso = SLA.serverNowIso;
    let warnThreshold = SLA.warnThresholdSeconds || 24 * 3600;

    let timerEl = document.getElementById('slaTimer');
    let badgeEl = document.getElementById('slaBadge');

    if (!dueIso) {
      if (timerEl) timerEl.innerText = 'Data scadentă nu este setată';
      if (badgeEl) badgeEl.style.background = '#999';
      return;
    }

    let dueMs = Date.parse(dueIso);
    if (isNaN(dueMs)) {
      if (timerEl) timerEl.innerText = 'Data scadentă invalidă';
      if (badgeEl) badgeEl.style.background = '#999';
      return;
    }

    // offset: clientNow - serverNow (ms)
    let clientServerOffset = Date.now() - Date.parse(serverNowIso);

    function remainingSeconds() {
      let estimatedServerNow = Date.now() - clientServerOffset;
      return Math.floor((dueMs - estimatedServerNow) / 1000);
    }

    function pad(n) {
      return String(n).padStart(2, '0');
    }

    // Format: days = 24h blocks; last day runs until dueMs (may be 18:00)
    function formatWithSeconds(totalSec) {
      if (totalSec <= 0) return 'Termen depășit';
      let days = Math.floor(totalSec / 86400);
      let rem = totalSec - days * 86400;
      let hours = Math.floor(rem / 3600);
      rem -= hours * 3600;
      let mins = Math.floor(rem / 60);
      let secs = rem % 60;

      let dayPart = '';
      if (days === 1) dayPart = '1 zi ';
      else if (days > 1) dayPart = days + ' zile ';

      if (days === 0) {
        return pad(hours) + ':' + pad(mins) + ':' + pad(secs);
      }
      return dayPart + pad(hours) + ':' + pad(mins) + ':' + pad(secs);
    }

    function updateSlaTimer() {
      let rem = remainingSeconds();
      if (rem <= 0) {
        if (timerEl) timerEl.innerText = 'Termen depășit';
        if (badgeEl) badgeEl.style.background = '#e74c3c';
        return;
      }

      if (rem <= warnThreshold) {
        if (badgeEl) badgeEl.style.background = '#f1c40f';
      } else {
        if (badgeEl) badgeEl.style.background = '#2ecc71';
      }

      if (timerEl) timerEl.innerText = formatWithSeconds(rem);
    }

    // Initial render + per-second tick
    updateSlaTimer();
    setInterval(updateSlaTimer, 1000);

    // Resync server time every 60s (no reload)
    // setInterval(function () {
    //   fetch('server_time.php', { cache: 'no-store' })
    //     .then((r) => r.json())
    //     .then((data) => {
    //       if (data && data.serverNowIso) {
    //        let newServerMs = Date.parse(data.serverNowIso);
    //         if (!isNaN(newServerMs)) {
    //           clientServerOffset = Date.now() - newServerMs;
    //         }
    //       }
    //     })
    //     .catch((err) => console.warn('SLA resync failed', err));
    // }, 60000);
  })();
})(); // end of View Order IIFE

/**
 * ============================================================
 * SECTION: Server-side quiet refresh (multi-user live updates)
 * ------------------------------------------------------------
 * The app is used by several logged-in users at the same time. When one of
 * them adds an order / delivers one / changes a status / etc., everyone
 * else who is currently looking at a view that WOULD show that change
 * should see it appear by itself — without reloading the page.
 *
 * This is the client half. It polls refresh_check.php in the background,
 * sending the current filters/sort/page plus a hash of the table the
 * browser is showing. The server re-hashes the CURRENT data for those exact
 * filters. If the hash differs, our view has been changed by another user,
 * so we call the existing window.quietRefresh() to patch the table in place.
 *
 * The safety rules enforced here:
 *   - DO NOT refresh while the "Add order" sidebar has ANY filled field
 *     (we must never throw away somebody's in-progress draft).
 *   - DO NOT refresh when the current filters wouldn't even show the change
 *     (in that case refresh_check.php already answers changed:false).
 *   - DO NOT refresh in the seconds right after the LOCAL user themself made
 *     a change (we don't want to echo our own action back at us).
 *   - DO NOT refresh while the order/statistics slider or a modal is open
 *     (that user is focusing on a detail view, not on "just looking" at the
 *     table). Closing the slider already triggers its own quiet refresh.
 * ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
  const addForm = document.getElementById('orderForm'); // dashboard only
  if (!addForm) return; // not the dashboard — nothing to auto-refresh

  const POLL_INTERVAL_MS = 10000; // how often we ask the server about changes

  /* ------------------------------------------------------------
   * Dirty detection for the "Add order" form.
   * We snapshot the serialised form after every real reset / submit and mark
   * the form "dirty" whenever the current serialisation differs from that
   * clean baseline (i.e. the user has typed or picked something).
   * ------------------------------------------------------------ */
  window.__orderFormCleanState = null;

  function captureOrderFormState() {
    if (typeof jQuery === 'undefined') return '';
    return $('#orderForm').serialize();
  }

  window.markOrderFormClean = function () {
    window.__orderFormCleanState = captureOrderFormState();
  };

  window.isOrderFormDirty = function () {
    if (window.__orderFormCleanState === null) {
      // No baseline yet (Select2 still warming up) — capture lazily and treat
      // an untouched form as clean so a legitimate refresh is never blocked.
      window.__orderFormCleanState = captureOrderFormState();
      return false;
    }
    return captureOrderFormState() !== window.__orderFormCleanState;
  };

  // Seed the baseline now; DOMContentLoaded here runs after the Select2 init
  // listeners, so the default selections (category, date, operator) are in.
  window.markOrderFormClean();

  /* ---- Did the LOCAL user just make the change themselves? ------------ */
  function selfChangeGraceActive() {
    return (
      window.__autoRefreshSuppressUntil &&
      Date.now() < window.__autoRefreshSuppressUntil
    );
  }

  /* ---- Is the user focusing on a detail view / modal instead? --------- */
  function detailViewBusy() {
    const panel = document.getElementById('orderSliderPanel');
    if (panel && panel.classList.contains('open')) return true;
    if (typeof jQuery !== 'undefined') {
      // Any in-page modal (notes, WhatsApp sender, ...) counts as "busy".
      if ($('.modal:visible').length > 0) return true;
    }
    return false;
  }

  /* ---- The view key = the exact filter/sort/page in the address bar. -- */
  function currentViewKey() {
    return window.location.search;
  }

  let lastKey = null;     // view key we have a baseline for
  let knownPageSig = null;  // hash of the table/pagination/pinned for lastKey
  let knownStatsSig = null; // hash of the stat cards currently on screen
  let checkInFlight = false;

  /* Update ONLY the stat-card banner numbers (with the odometer roll). Used
     when another user changed something that our filtered table wouldn't show,
     so a full quietRefresh() would be pointless — the stats still moved. */
  function applyStatCardsOnly(statsValues) {
    if (typeof window.animateStatCards !== 'function') return false;
    const banner = document.querySelector('.stats-banner');
    if (!banner || !statsValues) return false;

    // Build a synthetic banner carrying the NEW numbers, then hand both to the
    // existing animateStatCards() so the old→new roll is animated correctly.
    const synthetic = banner.cloneNode(true);
    const map = [
      ['card-overdue', statsValues.overdue],
      ['card-active', statsValues.active],
      ['card-completed', statsValues.completed],
      ['card-deliver-today', statsValues.deliver_today],
      ['card-delivered-today', statsValues.delivered_today],
    ];
    map.forEach(([cls, val]) => {
      const el = synthetic.querySelector(`.${cls} h3`);
      if (el) el.textContent = val;
    });

    window.animateStatCards(banner, synthetic);
    return true;
  }

  async function runCheck() {
    if (checkInFlight || document.visibilityState !== 'visible') return;
    checkInFlight = true;

    try {
      const key = currentViewKey();

      const resp = await fetch('refresh_check.php' + (key || ''), {
        cache: 'no-store',
      });
      if (!resp.ok) return; // not authed / server hiccup — stay quiet
      const data = await resp.json();
      if (
        !data ||
        typeof data.pageSig !== 'string' ||
        typeof data.statsSig !== 'string'
      )
        return;

      // The user navigated to a different view while we were waiting.
      if (key !== currentViewKey()) return;

      if (key !== lastKey) {
        // New view (manual filter / sort / pagination change). The browser
        // already shows this view, so just take a fresh baseline — acting on
        // it would only cause a pointless refresh.
        lastKey = key;
        knownPageSig = data.pageSig;
        knownStatsSig = data.statsSig;
        return;
      }

      if (knownPageSig === null) {
        // First check for this view — capture the baseline without acting.
        knownPageSig = data.pageSig;
        knownStatsSig = data.statsSig;
        return;
      }

      const pageChanged = knownPageSig !== data.pageSig;
      const statsChanged = knownStatsSig !== data.statsSig;

      // Nothing this user sees (neither the table nor the stat cards) changed.
      if (!pageChanged && !statsChanged) return;

      // Change from ANOTHER user; honour the guards.
      if (selfChangeGraceActive()) return; // it's our own recent action
      if (window.isOrderFormDirty()) return; // never clobber a draft
      if (detailViewBusy()) return; // user isn't just "looking at the table"

      // A manual pagination / filter / sort refresh is already in flight (the
      // toolbar adds .is-loading while goQuietly() fetches). If we fire a full
      // quietRefresh() now it would race it and can undo the click — e.g. a
      // pagination link that appears to "not work". Skip this tick; the next
      // poll will pick the change up.
      if (document.querySelector('.filters.is-loading')) return;

      if (!pageChanged && statsChanged) {
        // Only the global stat cards moved (e.g. an added order that this
        // user's active filters wouldn't show). Refresh JUST the cards — do
        // not churn the table, pinned strip or form.
        applyStatCardsOnly(data.stats);
        knownStatsSig = data.statsSig;
        return;
      }

      // The table the user is looking at changed: full quiet refresh. It
      // swaps table, pinned strip, pagination AND the stat banner together.
      // resetForm:false means the "Add order" sidebar is never wiped.
      await window.quietRefresh(window.location.href, { resetForm: false });

      // The full refresh rendered the latest stats too, so both baselines now
      // match what is on screen.
      if (currentViewKey() === key) {
        knownPageSig = data.pageSig;
        knownStatsSig = data.statsSig;
      }
    } catch (err) {
      // Network blip — simply try again on the next tick.
    } finally {
      checkInFlight = false;
    }
  }

  // First poll quickly, then every interval, and re-check when the tab
  // regains focus (it may have missed a change while hidden in the background).
  if (document.visibilityState === 'visible') runCheck();
  setInterval(runCheck, POLL_INTERVAL_MS);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') runCheck();
  });
});
