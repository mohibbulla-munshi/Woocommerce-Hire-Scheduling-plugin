jQuery(document).ready(function($) {
    $('#hire_start_date, #hire_end_date').datepicker({
        dateFormat: 'yy-mm-dd',
        onSelect: function() {
            var startDate = $('#hire_start_date').val();
            var endDate = $('#hire_end_date').val();

            // Only proceed if both dates are selected
            if (startDate && endDate) {
                // Perform AJAX request to calculate the new price
                $.ajax({
                    type: 'POST',
                    url: whs_data.ajax_url,
                    data: {
                        action: 'whs_calculate_price',
                        start_date: startDate,
                        end_date: endDate,
                        product_id: whs_data.product_id
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#dynamic-price').html(response.data.new_price); // Set formatted price
                        } else {
                            $('#dynamic-price').text(''); // Clear on error
                        }
                    },
                    error: function() {
                        $('#dynamic-price').text(''); // Clear on error
                    }
                });
            } else {
                $('#dynamic-price').text(''); // Clear price if dates are not selected
            }
        }
    });

    // Additional feature: Clear dynamic price when date inputs are changed
    $('#hire_start_date, #hire_end_date').on('change', function() {
        $('#dynamic-price').text(''); // Clear the price display
    });
});
