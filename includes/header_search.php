<?php
$header_search_id = $header_search_id ?? 'portalSearch';
$header_search_placeholder = $header_search_placeholder ?? 'Search pages...';
?>
<div class="header-search">
    <div class="header-search-wrap unified-table-search-wrap">
        <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
        <input
            type="search"
            id="<?php echo htmlspecialchars($header_search_id, ENT_QUOTES, 'UTF-8'); ?>"
            name="search"
            class="form-control unified-table-search-input header-search-input"
            placeholder="<?php echo htmlspecialchars($header_search_placeholder, ENT_QUOTES, 'UTF-8'); ?>"
            autocomplete="off"
            aria-label="Search portal"
        >
        <button type="button" class="unified-table-search-clear header-search-clear" aria-label="Clear search" style="display:none;">&times;</button>
    </div>
</div>
