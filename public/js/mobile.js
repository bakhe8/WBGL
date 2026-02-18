/**
 * WBGL Mobile Interactions
 * Handles sidebar toggles and overlay management for mobile devices.
 */

document.addEventListener('DOMContentLoaded', function () {

    // Create and append overlay if doesn't exist
    let overlay = document.querySelector('.mobile-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'mobile-overlay';
        document.body.appendChild(overlay);
    }

    // Toggle Sidebar (Left)
    window.toggleSidebar = function () {
        const sidebar = document.querySelector('.sidebar');
        const timeline = document.querySelector('.timeline-panel') || document.querySelector('.timeline-sidebar');

        // Close timeline if open
        if (timeline && timeline.classList.contains('active')) {
            timeline.classList.remove('active');
        }

        sidebar.classList.toggle('active');
        toggleOverlay();
    };

    // Toggle Timeline (Right)
    window.toggleTimeline = function () {
        const sidebar = document.querySelector('.sidebar');
        const timeline = document.querySelector('.timeline-panel') || document.querySelector('.timeline-sidebar');

        // Close sidebar if open
        if (sidebar && sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
        }

        timeline.classList.toggle('active');
        toggleOverlay();
    };

    // Helper to sync overlay state
    function toggleOverlay() {
        const sidebar = document.querySelector('.sidebar');
        const timeline = document.querySelector('.timeline-panel') || document.querySelector('.timeline-sidebar');
        const isSidebarOpen = sidebar && sidebar.classList.contains('active');
        const isTimelineOpen = timeline && timeline.classList.contains('active');

        if (isSidebarOpen || isTimelineOpen) {
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
        } else {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    // Close everything when clicking overlay
    overlay.addEventListener('click', function () {
        const sidebar = document.querySelector('.sidebar');
        const timeline = document.querySelector('.timeline-panel') || document.querySelector('.timeline-sidebar');

        if (sidebar) sidebar.classList.remove('active');
        if (timeline) timeline.classList.remove('active');
        toggleOverlay();
    });

});
