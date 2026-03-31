<?php
// partials/footer.php
declare(strict_types=1);

if (!isset($footerNote)) {
  $footerNote = "School project. Not affiliated with Wizards of the Coast.";
}
?>
<footer class="siteFooter">
  <div class="wrap">
    <small><?= h((string)$footerNote) ?></small>
  </div>
</footer>

<style>
  .siteFooter{
    border-top:1px solid var(--border);
    color:var(--muted);
    padding:14px 0;
  }
</style>