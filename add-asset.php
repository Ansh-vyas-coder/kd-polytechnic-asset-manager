<?php
$embedMode = isset($_GET['embed']) && $_GET['embed'] === '1';
if (!$embedMode) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Asset - K.D. Polytechnic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">
<?php
}
?>
    <div class="min-h-screen bg-slate-100 px-4 py-6 sm:px-6 lg:px-8">
        <div class="w-full max-w-5xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 lg:p-10">
            <div class="mb-6">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Add New Asset</h1>
                <p class="mt-1 text-sm text-slate-500">Add a new equipment record into the official departmental ledger.</p>
            </div>

            <div>

                <form id="assetForm" class="grid gap-6 lg:grid-cols-2" action="#" method="post" novalidate>
                    <div class="space-y-5 lg:col-span-2">
                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="asset_name" class="mb-2 block text-sm font-semibold text-slate-700">Asset Name</label>
                                <input type="text" id="asset_name" name="asset_name" placeholder="Keyboard" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                            </div>

                            <div>
                                <label for="category" class="mb-2 block text-sm font-semibold text-slate-700">Category</label>
                                <select id="category" name="category_id" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                                    <option value="">Select category</option>
                                    <option value="1">Expandable</option>
                                    <option value="2">Consumables</option>
                                    <option value="3">Deadstock</option>
                                    <option value="4">Furniture</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="quantity" class="mb-2 block text-sm font-semibold text-slate-700">Quantity</label>
                                <input type="number" id="quantity" name="quantity" min="1" value="1" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                            </div>

                            <div>
                                <label for="item_no" class="mb-2 block text-sm font-semibold text-slate-700">Item No</label>
                                <input type="text" id="item_no" name="item_no" placeholder="KDP/COMP/2028/EXP/P-49/I-125/10/30" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                            </div>
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="cost" class="mb-2 block text-sm font-semibold text-slate-700">Amount per Item</label>
                                <input type="number" id="cost" name="cost" step="0.01" placeholder="₹ Amount" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                            </div>

                            <div>
                                <label for="location" class="mb-2 block text-sm font-semibold text-slate-700">Location</label>
                                <select id="location" name="location" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                                    <option value="">Select location</option>
                                </select>
                                <div id="custom_location_wrapper" class="mt-3 hidden">
                                    <label for="custom_location" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">Add new location</label>
                                    <div class="flex gap-2">
                                        <input type="text" id="custom_location" placeholder="Enter new lab name" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                                        <button type="button" id="add_location_btn" class="rounded-lg border border-blue-600 bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-blue-700">Add</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="date_of_issue" class="mb-2 block text-sm font-semibold text-slate-700">Date of Issue</label>
                                <input type="date" id="date_of_issue" name="date_of_issue" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200" required>
                            </div>

                            <div>
                                <label for="assigned_to" class="mb-2 block text-sm font-semibold text-slate-700">Assign to Faculty</label>
                                <select id="assigned_to" name="assigned_to" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200">
                                    <option value="">Loading faculty...</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-5 md:grid-cols-2">
                            <div>
                                <label for="total_amount" class="mb-2 block text-sm font-semibold text-slate-700">Total Amount</label>
                                <input type="text" id="total_amount" name="total_amount" readonly placeholder="Auto-calculated" class="w-full rounded-lg border border-slate-300 bg-gray-100 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none">
                            </div>
                        </div>

                        <div>
                            <label for="remarks" class="mb-2 block text-sm font-semibold text-slate-700">Remarks</label>
                            <textarea id="remarks" name="remarks" rows="3" placeholder="Enter any specific condition or notes..." class="w-full rounded-lg border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-200"></textarea>
                        </div>
                    </div>

                    <div class="lg:col-span-2 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <a href="dashboard.php?view=dashboard" class="inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                            Cancel
                        </a>
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-blue-700 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-700/20 transition hover:bg-blue-800">
                            Save Asset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<script>
    const assetForm = document.getElementById('assetForm');
    const quantityInput = document.getElementById('quantity');
    const costInput = document.getElementById('cost');
    const totalAmountInput = document.getElementById('total_amount');
    const categoryInput = document.getElementById('category');
    const dateInput = document.getElementById('date_of_issue');
    const itemNoInput = document.getElementById('item_no');
    const locationSelect = document.getElementById('location');
    const customLocationInput = document.getElementById('custom_location');
    const customLocationWrapper = document.getElementById('custom_location_wrapper');
    const addLocationButton = document.getElementById('add_location_btn');
    const assignedToSelect = document.getElementById('assigned_to');
    const locationStorageKey = 'kd_polytechnic_saved_locations';

    function getCategoryCode(value) {
        const categoryMap = {
            '1': 'EXP',
            '2': 'CONS',
            '3': 'DS',
            '4': 'FUR'
        };
        return categoryMap[value] || '';
    }

    function updateItemNo() {
        const categoryValue = categoryInput.value;
        const categoryCode = getCategoryCode(categoryValue);
        const year = new Date().getFullYear();

        if (categoryCode) {
            itemNoInput.value = `KDP/COMP/${year}/${categoryCode}`;
        } else {
            itemNoInput.value = '';
        }
    }

    function calculateTotal() {
        const quantity = parseFloat(quantityInput.value) || 0;
        const cost = parseFloat(costInput.value) || 0;
        const total = quantity * cost;
        totalAmountInput.value = total > 0 ? total.toFixed(2) : '';
    }

    function getSavedLocations() {
        try {
            return JSON.parse(localStorage.getItem(locationStorageKey)) || [];
        } catch (error) {
            return [];
        }
    }

    function populateLocationOptions() {
        const defaults = ['F004', 'Lab 1', 'Lab 2'];
        const savedLocations = getSavedLocations();
        const allLocations = [...new Set([...defaults, ...savedLocations])];
        const currentValue = locationSelect.value;

        locationSelect.innerHTML = '';

        const selectOption = document.createElement('option');
        selectOption.value = '';
        selectOption.textContent = 'Select location';
        locationSelect.appendChild(selectOption);

        allLocations.forEach(location => {
            const option = document.createElement('option');
            option.value = location;
            option.textContent = location;
            locationSelect.appendChild(option);
        });

        const otherOption = document.createElement('option');
        otherOption.value = '__other__';
        otherOption.textContent = 'Other';
        locationSelect.appendChild(otherOption);

        if (currentValue && Array.from(locationSelect.options).some(option => option.value === currentValue)) {
            locationSelect.value = currentValue;
        } else if (currentValue === '__other__') {
            locationSelect.value = '__other__';
        }
    }

    function toggleCustomLocationInput() {
        const showCustomInput = locationSelect.value === '__other__';
        customLocationWrapper.classList.toggle('hidden', !showCustomInput);
        if (!showCustomInput) {
            customLocationInput.value = '';
        }
    }

    function addCustomLocation() {
        const newLocation = customLocationInput.value.trim();
        if (!newLocation) {
            alert('Please enter a new location name.');
            return;
        }

        const savedLocations = getSavedLocations();
        if (!savedLocations.includes(newLocation)) {
            savedLocations.push(newLocation);
            localStorage.setItem(locationStorageKey, JSON.stringify(savedLocations));
        }

        populateLocationOptions();
        locationSelect.value = newLocation;
        customLocationInput.value = '';
        customLocationWrapper.classList.add('hidden');
    }

    [quantityInput, costInput, categoryInput].forEach(input => {
        input.addEventListener('input', () => {
            calculateTotal();
            updateItemNo();
        });
        input.addEventListener('change', () => {
            calculateTotal();
            updateItemNo();
        });
    });

    categoryInput.addEventListener('change', updateItemNo);

    locationSelect.addEventListener('change', toggleCustomLocationInput);
    addLocationButton.addEventListener('click', addCustomLocation);
    customLocationInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            addCustomLocation();
        }
    });

    assetForm.addEventListener('submit', function (event) {
        const selectedLocation = locationSelect.value.trim();
        const locationFilled = selectedLocation !== '' && selectedLocation !== '__other__';
        const facultyFilled = assignedToSelect.value.trim() !== '';

        if (!locationFilled && !facultyFilled) {
            event.preventDefault();
            alert('Please fill at least one of the following: Location or Assign to Faculty.');
            return;
        }

        if (selectedLocation === '__other__') {
            event.preventDefault();
            alert('Please add a new location name or choose an existing one.');
            return;
        }

        const requiredFields = [
            document.getElementById('asset_name'),
            categoryInput,
            quantityInput,
            itemNoInput,
            costInput,
            dateInput
        ];

        for (const field of requiredFields) {
            if (!field.value || field.value.trim() === '') {
                event.preventDefault();
                alert('Please fill all required fields in the correct format.');
                return;
            }
        }

        if (!/^[A-Za-z0-9/\- ]+$/.test(itemNoInput.value.trim())) {
            event.preventDefault();
            alert('Item No must be in a valid format.');
            return;
        }

        if (parseFloat(quantityInput.value) < 1) {
            event.preventDefault();
            alert('Quantity must be at least 1.');
            return;
        }

        if (parseFloat(costInput.value) < 0) {
            event.preventDefault();
            alert('Amount per Item cannot be negative.');
            return;
        }
    });

    fetch('get-faculty.php')
        .then(response => response.json())
        .then(data => {
            assignedToSelect.innerHTML = '<option value="">Select faculty</option>';
            data.forEach(user => {
                const option = document.createElement('option');
                option.value = user.full_name;
                option.textContent = user.full_name;
                assignedToSelect.appendChild(option);
            });
        })
        .catch(() => {
            assignedToSelect.innerHTML = '<option value="">No faculty available</option>';
        });

    populateLocationOptions();
    toggleCustomLocationInput();
    updateItemNo();
</script>
<?php if (!$embedMode) { ?>
</body>
</html>
<?php } ?>
