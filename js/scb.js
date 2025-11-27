/* Comment Beautifier - Minimalist Styles */
(function($){
  $(function(){
    
    // Selection handling


    function updateSelectionCount(){
      var selected = $('.cb-check:checked').length;
      $('.cb-pro-selection-count').text(selected + ' selected');
      
      // Update UI based on selection
      if(selected > 0){
        $('.cb-pro-action-bar').addClass('has-selection');
      } else {
        $('.cb-pro-action-bar').removeClass('has-selection');
      }
    }
    
    // Select all functionality
    $(document).on('change', '#cb-check-all', function(){
      var isChecked = $(this).is(':checked');
      
      console.log('Select All clicked - Checked:', isChecked);
      
      // Select all visible comments
      var visibleCards = $('.cb-pro-comment-card:visible');
      var visibleCheckboxes = $('.cb-pro-comment-card:visible .cb-check');
      
      console.log('Visible cards:', visibleCards.length);
      console.log('Visible checkboxes:', visibleCheckboxes.length);
      
      visibleCheckboxes.prop('checked', isChecked);
      
      updateSelectionCount();
    });
    
    $(document).on('change', '.cb-check', updateSelectionCount);
    
    // Initial count
    updateSelectionCount();



    // Bulk actions


    $('#cb-select-visible').on('click', function(e){ 
      e.preventDefault(); 
      $('.cb-pro-comment-card:visible').each(function(){
        $(this).find('.cb-check').prop('checked', true);
      });
      updateSelectionCount();
    });
    
    $('#cb-select-has-link').on('click', function(e){ 
      e.preventDefault(); 
      $('.cb-pro-comment-card').each(function(){
        var hasLink = $(this).data('has-link') == '1';
        $(this).find('.cb-check').prop('checked', hasLink);
      });
      updateSelectionCount();
    });



    // Single URL removal
$(document).on('click', '.cb-pro-action-btn.remove-url', function(){
  var $btn = $(this);
  var id = $btn.data('id');
  
  if(!confirm('Remove URLs from this comment?')) return;
  
  $.post(cb_ajax.ajax_url, {
    action: 'cb_remove_urls_only',
    nonce: cb_ajax.nonce,
    ids: [id]
  }, function(response){
    if(response.success){
      $btn.closest('.cb-pro-comment-card')
        .data('has-link', '0')
        .find('.cb-pro-badge.has-link')
        .remove();
      $btn.remove();
      showStatus('URLs removed successfully!', 'success');
    } else {
      showStatus('Error removing URLs: ' + response.data, 'error');
    }
  });
});

    // Search & filters


    function applyFilters(){
        var searchQuery = $('#cb-search-input').val().toLowerCase().trim();
        var hasProfileFilter = $('#cb-filter-has-profile-url').is(':checked');
        var onlyLinksFilter = $('#cb-setting-only-links').is(':checked');
        
        // Only apply "only links" filter to overview page
        var currentPage = window.location.href;
        var isOverviewPage = currentPage.indexOf('comment-beautifier-pro') > -1 && 
                            currentPage.indexOf('awaiting') === -1 && 
                            currentPage.indexOf('settings') === -1;
        var applyOnlyLinksFilter = (onlyLinksFilter && isOverviewPage);
        
        $('.cb-pro-comment-card').each(function(){
            var $card = $(this);
            var commentText = $card.find('.cb-pro-comment-content').text().toLowerCase();
            var authorText = $card.find('.cb-pro-author-name').text().toLowerCase();
            var emailText = $card.find('.cb-pro-author-email').text().toLowerCase();
            
            var hasProfile = $card.data('has-profile') == '1';
            var hasLink = $card.data('has-link') == '1';
            
            var show = true;
            
            // Search filter
            if(searchQuery && !(commentText.includes(searchQuery) || authorText.includes(searchQuery) || emailText.includes(searchQuery))){
                show = false;
            }
            
            // Profile filter
            if(hasProfileFilter && !hasProfile){
                show = false;
            }
            
            // Only links filter - Only apply to overview page
            if(applyOnlyLinksFilter && !hasLink){
                show = false;
            }
            
            $card.toggle(show);
        });
        
        // Show empty state if no results
        var visibleCards = $('.cb-pro-comment-card:visible').length;
        if(visibleCards === 0){
            if($('#cb-comments-container .cb-pro-empty-state').length === 0){
                $('#cb-comments-container').append(
                    '<div class="cb-pro-empty-state">' +
                      '<span class="dashicons dashicons-search"></span>' +
                      '<h3>No comments found</h3>' +
                      '<p>Try adjusting your search or filter criteria.</p>' +
                    '</div>'
                );
            }
        } else {
            $('#cb-comments-container .cb-pro-empty-state').remove();
        }
        
        updateSelectionCount(); // Update selection count after filtering
    }
    
    // ---------- Save Settings ----------
    $('#cb-save-settings').on('click', function(){
        var comments = $('#cb-canned-comments').val();
        var names = $('#cb-canned-names').val();
        var commentsPerPage = $('#cb-comments-per-page').val();
        var removeUrls = $('#cb-setting-remove-urls').is(':checked') ? '1' : '0';
        var onlyLinks = $('#cb-setting-only-links').is(':checked') ? '1' : '0';

        $('#cb-save-status').text('Saving...');

        $.post(cb_ajax.ajax_url, {
            action: 'cb_save_settings',
            nonce: cb_ajax.nonce,
            comments: comments,
            names: names,
            comments_per_page: commentsPerPage,
            remove_urls: removeUrls,
            only_links: onlyLinks
        }, function(response){
            if(response.success){
                $('#cb-save-status').text('Settings saved successfully!');
                setTimeout(function(){
                    $('#cb-save-status').text('');
                }, 1500);
            } else {
                $('#cb-save-status').text('Error saving settings: ' + response.data);
            }
        });
    });

    // ---------- Remove URLs Only (Bulk Action) ----------
    $(document).on('click', '#cb-remove-urls-btn', function(){
      var selectedIds = $('.cb-check:checked').map(function(){ 
        return $(this).val(); 
      }).get();
      
      if(!selectedIds.length){
        showStatus('Please select comments to remove URLs from.', 'error');
        return;
      }
      
      // Filter to only comments with links
      var commentsWithLinks = selectedIds.filter(function(id){
        var $card = $('.cb-pro-comment-card[data-comment-id="'+id+'"]');
        return $card.data('has-link') == '1';
      });
      
      if(!commentsWithLinks.length){
        showStatus('No selected comments contain URLs.', 'error');
        return;
      }
      
      if(!confirm('Remove URLs from ' + commentsWithLinks.length + ' comments?')){
        return;
      }
      
      showStatus('Removing URLs...', 'success');
      
      $.post(cb_ajax.ajax_url, {
        action: 'cb_remove_urls_only',
        nonce: cb_ajax.nonce,
        ids: commentsWithLinks
      }, function(response){
        if(response.success){
          showStatus('Successfully removed URLs from ' + response.data.updated + ' comments. Refreshing...', 'success');
          setTimeout(function(){ 
            location.reload(); 
          }, 1500);
        } else {
          showStatus('Error: ' + (response.data || 'Unknown error occurred'), 'error');
        }
      });
    });

    // ---------- Single Profile Removal ----------
    $(document).on('click', '.cb-pro-action-btn.remove-profile', function(){
      var $btn = $(this);
      var id = $btn.data('id');
      
      if(!confirm('Remove profile URL from this comment?')) return;
      
      $.post(cb_ajax.ajax_url, {
        action: 'cb_remove_profile_urls',
        nonce: cb_ajax.nonce,
        ids: [id]
      }, function(response){
        if(response.success){
          $btn.closest('.cb-pro-comment-card')
            .data('has-profile', '0')
            .find('.cb-pro-badge.has-profile')
            .remove();
          $btn.remove();
          showStatus('Profile URL removed successfully!', 'success');
        } else {
          showStatus('Error removing profile URL: ' + response.data, 'error');
        }
      });
    });

    // ---------- Beautify Comments ----------
    $(document).on('click', '#cb-beautify-btn', function(){
      var selectedIds = $('.cb-check:checked').map(function(){ 
        return $(this).val(); 
      }).get();
      
      if(!selectedIds.length){
        showStatus('Please select at least one comment to beautify.', 'error');
        return;
      }
      
      var globalRemove = $('#cb-setting-remove-urls').is(':checked') ? 1 : 0;
      var onlyWithLinks = $('#cb-setting-only-links').is(':checked') ? 1 : 0;
      var autoApprove = $('#cb-setting-auto-approve').is(':checked') ? 1 : 0;
      var removeProfile = $('#cb-setting-remove-profile').is(':checked') ? 1 : 0;
      
      // Filter by links if needed
      if(onlyWithLinks){
        selectedIds = selectedIds.filter(function(id){
          var $card = $('.cb-pro-comment-card[data-comment-id="'+id+'"]');
          return $card.data('has-link') == '1';
        });
        
        if(!selectedIds.length){
          showStatus('No selected comments contain links.', 'error');
          return;
        }
      }
      
      var mode = $('#cb-mode-select').val();
      var changeAuthor = (mode === 'both' || mode === 'name') ? '1' : '0';
      var changeContent = (mode === 'both' || mode === 'content') ? '1' : '0';
      
      if(!confirm('This will overwrite the selected comments. Backups will be created. Continue?')){
        return;
      }
      
      showStatus('Processing comments...', 'success');
      
      $.post(cb_ajax.ajax_url, {
        action: 'cb_beautify_comments',
        nonce: cb_ajax.nonce,
        ids: selectedIds,
        change_author: changeAuthor,
        change_content: changeContent,
        remove_urls: globalRemove,
        move_with_links: onlyWithLinks,
        auto_approve: autoApprove,
        remove_profile: removeProfile,
        per_row_remove: JSON.stringify({})
      }, function(response){
        if(response.success){
          showStatus('Successfully updated ' + response.data.updated + ' comments. Refreshing...', 'success');
          setTimeout(function(){ 
            location.reload(); 
          }, 1500);
        } else {
          showStatus('Error: ' + (response.data || 'Unknown error occurred'), 'error');
        }
      }).fail(function(xhr){
        showStatus('Request failed: ' + xhr.status + ' ' + xhr.statusText, 'error');
      });
    });

    // ---------- Approve Comments ----------
    $(document).on('click', '#cb-approve-btn', function(){
      var selectedIds = $('.cb-check:checked').map(function(){ 
        return $(this).val(); 
      }).get();
      
      if(!selectedIds.length){
        showStatus('Please select comments to approve.', 'error');
        return;
      }
      
      showStatus('Approving comments...', 'success');
      
      $.post(cb_ajax.ajax_url, {
        action: 'cb_approve_comments',
        nonce: cb_ajax.nonce,
        ids: selectedIds
      }, function(response){
        if(response.success){
          showStatus('Successfully approved ' + response.data.approved + ' comments. Refreshing...', 'success');
          setTimeout(function(){ 
            location.reload(); 
          }, 1500);
        } else {
          showStatus('Error: ' + (response.data || 'Unknown error occurred'), 'error');
        }
      });
    });

    // ---------- Remove Profile URLs (Bulk) ----------
    $(document).on('click', '#cb-remove-profile-urls', function(){
      var selectedIds = $('.cb-check:checked').map(function(){ 
        return $(this).val(); 
      }).get();
      
      if(!selectedIds.length){
        showStatus('Please select comments to remove profile URLs from.', 'error');
        return;
      }
      
      // Filter to only comments with profile URLs
      var commentsWithProfile = selectedIds.filter(function(id){
        var $card = $('.cb-pro-comment-card[data-comment-id="'+id+'"]');
        return $card.data('has-profile') == '1';
      });
      
      if(!commentsWithProfile.length){
        showStatus('No selected comments have profile URLs.', 'error');
        return;
      }
      
      if(!confirm('Remove profile URLs from ' + commentsWithProfile.length + ' comments?')){
        return;
      }
      
      showStatus('Removing profile URLs...', 'success');
      
      $.post(cb_ajax.ajax_url, {
        action: 'cb_remove_profile_urls',
        nonce: cb_ajax.nonce,
        ids: commentsWithProfile
      }, function(response){
        if(response.success){
          showStatus('Successfully removed profile URLs from ' + response.data.removed + ' comments. Refreshing...', 'success');
          setTimeout(function(){ 
            location.reload(); 
          }, 1500);
        } else {
          showStatus('Error: ' + (response.data || 'Unknown error occurred'), 'error');
        }
      });
    });

    // ---------- Preview Changes ----------
    $(document).on('click', '#cb-preview-btn', function(){
      var selectedIds = $('.cb-check:checked').map(function(){ 
        return $(this).val(); 
      }).get();
      
      if(!selectedIds.length){
        showStatus('Please select comments to preview.', 'error');
        return;
      }
      
      var mode = $('#cb-mode-select').val();
      var changeAuthor = (mode === 'both' || mode === 'name') ? '1' : '0';
      var changeContent = (mode === 'both' || mode === 'content') ? '1' : '0';
      
      var cannedComments = $('#cb-canned-comments').val() ? 
        $('#cb-canned-comments').val().split('\n').map(function(s){ return s.trim(); }).filter(Boolean) : [];
      
      var cannedNames = $('#cb-canned-names').val() ? 
        $('#cb-canned-names').val().split('\n').map(function(s){ return s.trim(); }).filter(Boolean) : [];
      
      var globalRemove = $('#cb-setting-remove-urls').is(':checked') ? 1 : 0;
      var removeProfile = $('#cb-setting-remove-profile').is(':checked') ? 1 : 0;
      
      var previewHtml = '';
      
      $('.cb-pro-comment-card').each(function(){
        var $card = $(this);
        var id = $card.data('comment-id');
        
        if(selectedIds.indexOf(id.toString()) === -1) return;
        
        var originalContent = $card.find('.cb-pro-comment-content p').text();
        var originalAuthor = $card.find('.cb-pro-author-name').text();
        var originalProfile = $card.data('has-profile') == '1' ? 'Has Profile URL' : 'No Profile URL';
        
        var newContent = originalContent;
        var newAuthor = originalAuthor;
        var newProfile = originalProfile;
        
        // Apply content changes
        if(changeContent && cannedComments.length){
          newContent = cannedComments[Math.floor(Math.random() * cannedComments.length)];
        }
        
        // Apply author changes
        if(changeAuthor && cannedNames.length){
          newAuthor = cannedNames[Math.floor(Math.random() * cannedNames.length)];
        }
        
        // Apply URL removal
        if(globalRemove){
          newContent = newContent.replace(/https?:\/\/\S+|www\.\S+/gi, '[link removed]');
        }
        
        // Apply profile removal
        if(removeProfile){
          newProfile = 'No Profile URL';
        }
        
        previewHtml += 
          '<div class="cb-pro-preview-item">' +
            '<h4>Comment ID: ' + id + '</h4>' +
            '<div class="cb-pro-preview-content">' +
              '<div class="cb-pro-preview-old">' +
                '<strong>Original:</strong><br>' +
                '<strong>Author:</strong> ' + escapeHtml(originalAuthor) + '<br>' +
                '<strong>Profile:</strong> ' + originalProfile + '<br>' +
                '<strong>Content:</strong> ' + escapeHtml(originalContent) +
              '</div>' +
              '<div class="cb-pro-preview-new">' +
                '<strong>Preview:</strong><br>' +
                '<strong>Author:</strong> ' + escapeHtml(newAuthor) + '<br>' +
                '<strong>Profile:</strong> ' + newProfile + '<br>' +
                '<strong>Content:</strong> ' + escapeHtml(newContent) +
              '</div>' +
            '</div>' +
          '</div>';
      });
      
      $('#cb-preview-list').html(previewHtml);
      $('#cb-preview-modal').fadeIn(200);
    });

    // ---------- Close Preview Modal ----------
    $('#cb-close-preview, #cb-preview-modal').on('click', function(e){
      if(e.target === this || $(e.target).is('#cb-close-preview')){
        $('#cb-preview-modal').fadeOut(200);
      }
    });

    // ---------- View Post Tooltip ----------
    var tooltipTimeout;
    $(document).on('mouseenter', '.cb-pro-post-link', function(e){
      var $link = $(this);
      var title = $link.data('post-title');
      
      if(!title) return;
      
      clearTimeout(tooltipTimeout);
      
      tooltipTimeout = setTimeout(function(){
        var tooltip = $('<div class="cb-pro-tooltip">' + title + '</div>');
        $('body').append(tooltip);
        
        var linkOffset = $link.offset();
        tooltip.css({
          left: linkOffset.left + $link.outerWidth() + 10,
          top: linkOffset.top,
          opacity: 0
        });
        
        tooltip.animate({opacity: 1}, 200);
        
        $link.data('tooltip', tooltip);
      }, 500);
    });
    
    $(document).on('mouseleave', '.cb-pro-post-link', function(){
      clearTimeout(tooltipTimeout);
      var $link = $(this);
      var tooltip = $link.data('tooltip');
      if(tooltip){
        tooltip.animate({opacity: 0}, 200, function(){
          $(this).remove();
        });
      }
    });

    // ---------- Event Listeners ----------
    $('#cb-search-input').on('keyup', applyFilters);
    $('#cb-filter-has-profile-url').on('change', applyFilters);
    $('#cb-setting-only-links').on('change', applyFilters);

    // ---------- Utility Functions ----------
    function showStatus(message, type){
      var $status = $('#cb-status');
      $status.removeClass('success error').addClass(type).text(message).show();
      
      if(type === 'success'){
        setTimeout(function(){
          $status.fadeOut(300);
        }, 5000);
      }
    }
    
    function escapeHtml(text) {
      var div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    // Initialize toggle switches properly
    function initializeToggles() {
      $('.cb-pro-toggle-switch input').each(function() {
        var $input = $(this);
        var $slider = $input.siblings('.cb-pro-toggle-slider');
        
        if($input.is(':checked')) {
          $slider.addClass('checked');
        } else {
          $slider.removeClass('checked');
        }
      });
    }

    // Initialize everything when page loads
    function initializePage() {
      applyFilters();
      initializeToggles();
      
      // Set up toggle switch event handlers
      $('.cb-pro-toggle-switch input').on('change', function() {
        var $slider = $(this).siblings('.cb-pro-toggle-slider');
        if($(this).is(':checked')) {
          $slider.addClass('checked');
        } else {
          $slider.removeClass('checked');
        }
      });
    }

    // Initialize the page
    initializePage();
    
  });
})(jQuery);