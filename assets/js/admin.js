(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Toggle weekday field
        function toggleWeekdayField() {
            var frequency = $('#fdu_settings_frequency').val();
            var weekdayRow = $('#fdu_settings_weekday').closest('tr');
            
            if (frequency === 'weekly') {
                weekdayRow.fadeIn(200);
            } else {
                weekdayRow.fadeOut(200);
            }
        }
        
        toggleWeekdayField();
        $('#fdu_settings_frequency').on('change', toggleWeekdayField);
    });
    
})(jQuery);
