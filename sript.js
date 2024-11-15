function formatDateWithoutYearWithDay(dateString) {
    var date = new Date(dateString);
    var day = date.getDate();
    var month = date.getMonth() + 1; // Months are zero-based
    var daysOfWeek = ['Duminică', 'Luni', 'Marți', 'Miercuri', 'Joi', 'Vineri', 'Sâmbătă'];
    var dayOfWeek = daysOfWeek[date.getDay()];
    return dayOfWeek + ', ' + (day < 10 ? '0' : '') + day + '.' + (month < 10 ? '0' : '') + month;
}

function formatRemainingDays(dueDate) {
    var currentDate = new Date();
    var dueDateObj = new Date(dueDate);
    var timeDiff = dueDateObj - currentDate;
    var daysDiff = Math.ceil(timeDiff / (1000 * 3600 * 24));
    var daysOfWeek = ['Duminică', 'Luni', 'Marți', 'Miercuri', 'Joi', 'Vineri', 'Sâmbătă'];
    var dayOfWeek = daysOfWeek[dueDateObj.getDay()];
    if (daysDiff >= 0) {
        return dayOfWeek + ', ' + daysDiff + ' zile rămase';
    } else {
        return dayOfWeek + ', 0 zile rămase';
    }
}

function toggleClientFields() {
    var clientSelect = document.getElementById('client_id');
    var newClientFields = document.getElementById('new_client_fields');
    var clientDetailsButton = document.getElementById('client_details_button');
    var clientDetails = document.getElementById('client_details');
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

function fetchClientDetails(clientId) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'dashboard.php?fetch_client_details=true&client_id=' + clientId, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4 && xhr.status == 200) {
            var client = JSON.parse(xhr.responseText);
            document.getElementById('client_name_display').innerText = client.client_name;
            document.getElementById('client_email_display').innerText = client.client_email;
            document.getElementById('client_phone_display').innerText = client.client_phone;
            document.getElementById('client_id_edit').value = client.client_id;
            document.getElementById('client_name_edit').value = client.client_name;
            document.getElementById('client_email_edit').value = client.client_email;
            document.getElementById('client_phone_edit').value = client.client_phone;
            document.getElementById('client_details').style.display = 'block';
        }
    };
    xhr.send();
}

function validateDueDateTime() {
    var dueDate = document.getElementById('due_date').value;
    var dueDateObj = new Date(dueDate);
    var currentDate = new Date();
    if (dueDateObj <= currentDate) {
        alert("Data livrării trebuie să fie în viitor.");
        return false;
    }
    return true;
}

function submitOrderForm(event) {
    event.preventDefault();
    if (!validateDueDateTime()) {
        return;
    }
    var formData = new FormData(document.getElementById('orderForm'));
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'dashboard.php', true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4 && xhr.status == 200) {
            var response = JSON.parse(xhr.responseText);
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

function submitEditClientForm(event) {
    event.preventDefault();
    var formData = new FormData(document.getElementById('editClientForm'));
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'edit_client.php', true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4 && xhr.status == 200) {
            alert('Detaliile clientului au fost actualizate cu succes.');
        }
    };
    xhr.send(formData);
}

function toggleClientDetails() {
    var clientSelect = document.getElementById('client_id');
    var clientId = clientSelect.value;
    if (clientId) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'dashboard.php?fetch_client_details=true&client_id=' + clientId, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState == 4 && xhr.status == 200) {
                var client = JSON.parse(xhr.responseText);
                document.getElementById('client_name_display').innerText = client.client_name;
                document.getElementById('client_email_display').innerText = client.client_email;
                document.getElementById('client_phone_display').innerText = client.client_phone;
                document.getElementById('client_id_edit').value = client.client_id;
                document.getElementById('client_name_edit').value = client.client_name;
                document.getElementById('client_email_edit').value = client.client_email;
                document.getElementById('client_phone_edit').value = client.client_phone;
                document.getElementById('client_details').style.display = 'block';
            }
        };
        xhr.send();
    }
}

// Show new client fields by default and set default due date/time
document.addEventListener('DOMContentLoaded', function() {
    var newClientFields = document.getElementById('new_client_fields');
    newClientFields.style.display = 'none';
    var currentYear = new Date().getFullYear();
    document.getElementById('currentYear').innerText = currentYear;

    // Set default due date and time
    var currentDate = new Date();
    currentDate.setHours(currentDate.getHours() + 2);
    var dueDate = currentDate.toISOString().split('T')[0];
    var dueTime = currentDate.toTimeString().split(' ')[0].substring(0, 5);
    document.getElementById('due_date').value = dueDate;
    document.getElementById('due_time').value = dueTime;
});