<?php if (!empty($_SESSION['user_id']) || !empty($public)): ?>
<footer class="foot">
  <span>CarbonCalc · DBMS mini project</span>
  <span>MySQL for records, MongoDB for the event log</span>
  <span>Emission factors: DEFRA, CEA, IPCC, EWG, Poore &amp; Nemecek · <a href="<?= base_url() ?>methodology.php#sources">see sources</a></span>
</footer>
</main>
<?php if (!empty($_SESSION['user_id'])): ?></div><?php endif; ?>
<?php else: ?>
</main>
<?php endif; ?>
</body>
</html>
