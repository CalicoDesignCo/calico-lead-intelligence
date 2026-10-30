jQuery(function($){
    var reclassToken = '';
    function post(action,data,out,done){
        $(out).text('Running...');
        $.post(CLI_ADMIN.ajaxUrl,$.extend({action:action,nonce:CLI_ADMIN.nonce},data||{}))
            .done(function(r){
                $(out).text(JSON.stringify(r.data,null,2));
                if(done) done(r);
            })
            .fail(function(x){
                var message='Request failed: '+x.status;
                if(x.responseJSON&&x.responseJSON.data&&x.responseJSON.data.message) message+=' - '+x.responseJSON.data.message;
                $(out).text(message);
            });
    }
    $('#cli-run-rule-test').on('click',function(){post('cli_rule_test',{url:$('#cli-test-url').val(),referrer:$('#cli-test-referrer').val()},'#cli-rule-test-output')});
    $('#cli-run-audit').on('click',function(){post('cli_audit_leads',{},'#cli-audit-output')});
    $('#cli-run-regression').on('click',function(){post('cli_regression_tests',{},'#cli-regression-output')});
    $('#cli-clean-unknowns').on('click',function(){if(!window.confirm('Exclude blank and Unknown sources from reports? Gravity Forms entries will remain unchanged.'))return;post('cli_clean_unknowns',{},'#cli-clean-output')});
    $('#cli-restore-cleaned').on('click',function(){if(!window.confirm('Include blank and Unknown sources in reports? Gravity Forms entries will remain unchanged.'))return;post('cli_restore_cleaned',{},'#cli-clean-output')});
    $('#cli-run-diagnostics').on('click',function(){var local={first_touch:localStorage.getItem('cli_first_touch'),last_touch:localStorage.getItem('cli_last_touch'),session:localStorage.getItem('cli_session')};post('cli_diagnostics',{browser:JSON.stringify(local)},'#cli-diagnostics-output')});
    $('#cli-preview-reclass').on('click',function(){
        reclassToken=''; $('#cli-apply-reclass').prop('disabled',true);
        post('cli_preview_reclassification',{days:$('#cli-reclass-days').val(),legacy_only:$('#cli-reclass-legacy-only').is(':checked')?1:0},'#cli-reclass-output',function(r){
            if(r.success&&r.data&&r.data.token){
                reclassToken=r.data.token;
                $('#cli-apply-reclass').prop('disabled',!(r.data.changing>0));
            }
        });
    });
    $('#cli-apply-reclass').on('click',function(){
        if(!reclassToken){window.alert('Run a fresh preview first.');return;}
        if(!window.confirm('Recalculate reports with the current attribution rules? Gravity Forms entries and metadata will remain unchanged.'))return;
        $('#cli-apply-reclass').prop('disabled',true);
        post('cli_apply_reclassification',{token:reclassToken},'#cli-reclass-output',function(){reclassToken='';});
    });
});

jQuery(function($){
    $('#cli-add-map-row').on('click',function(){
        $('#cli-campaign-map-rows').append('<tr><td><input type="text" name="cli_campaign_map_id[]" value="" class="regular-text code" placeholder="20058424758"></td><td><input type="text" name="cli_campaign_map_name[]" value="" class="regular-text" placeholder="Commercial Search"></td><td><button type="button" class="button-link-delete cli-remove-map-row">Remove</button></td></tr>');
    });
    $(document).on('click','.cli-remove-map-row',function(){
        var $rows=$('#cli-campaign-map-rows tr');
        if($rows.length>1){$(this).closest('tr').remove();}else{$(this).closest('tr').find('input').val('');}
    });
});

jQuery(function($){
    var logoFrame;
    $('#cli-report-logo-select').on('click',function(event){
        event.preventDefault();
        if(!window.wp||!wp.media){window.alert('The WordPress Media Library is unavailable.');return;}
        if(logoFrame){logoFrame.open();return;}
        logoFrame=wp.media({
            title:'Choose Client Logo',
            button:{text:'Use This Logo'},
            library:{type:'image'},
            multiple:false
        });
        logoFrame.on('select',function(){
            var attachment=logoFrame.state().get('selection').first().toJSON();
            var url=attachment.sizes&&attachment.sizes.medium?attachment.sizes.medium.url:attachment.url;
            $('#cli-report-logo-id').val(attachment.id);
            $('#cli-report-logo-preview').html($('<img>',{src:url,alt:''}));
            $('#cli-report-logo-remove').show();
        });
        logoFrame.open();
    });
    $('#cli-report-logo-remove').on('click',function(event){
        event.preventDefault();
        $('#cli-report-logo-id').val('0');
        $('#cli-report-logo-preview').html($('<span>').text('No logo selected.'));
        $(this).hide();
    });
});
