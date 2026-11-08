<?php
require_once __DIR__ . '/../includes/auth.php';
requireAuth('ai-assistant');
require_once __DIR__ . '/../components/layout.php';
 render_layout_open('ai-assistant'); ?>
<?php page_header('AI Inventory Assistant','Ask plain-language questions — answered from your live database'); ?>
<div class="ai-chat-card">
  <div class="ai-chat-header">
    <div class="ai-badge-dot"></div>
    <span class="ai-header-name">AI Inventory Assistant</span>
    <span class="ai-header-sub">Live MySQL data · Claude API</span>
  </div>
  <div class="ai-messages" id="ai-messages"></div>
  <div class="ai-chips">
    <span class="ai-chip" onclick="useChip('Which products are at critical stockout risk?','ai-input','ai-messages')">Stockout risks?</span>
    <span class="ai-chip" onclick="useChip('How is supplier on-time delivery performance?','ai-input','ai-messages')">Supplier performance?</span>
    <span class="ai-chip" onclick="useChip('What stock is expiring soon?','ai-input','ai-messages')">Expiry alerts?</span>
    <span class="ai-chip" onclick="useChip('Which products have excess stock?','ai-input','ai-messages')">Excess stock?</span>
    <span class="ai-chip" onclick="useChip('What products need to be reordered?','ai-input','ai-messages')">Reorder priorities?</span>
    <span class="ai-chip" onclick="useChip('Show me PO reconciliation issues','ai-input','ai-messages')">PO issues?</span>
    <span class="ai-chip" onclick="useChip('Show me mismatch analysis','ai-input','ai-messages')">Mismatch?</span>
    <span class="ai-chip" onclick="useChip('Explain ABC/XYZ classification','ai-input','ai-messages')">ABC/XYZ?</span>
  </div>
  <div class="ai-input-row">
    <input class="ai-input" id="ai-input" data-container="ai-messages" placeholder="Ask anything about your inventory data…">
    <button class="ai-send" onclick="sendAIMessage('ai-input','ai-messages')"><?=icon('send')?> Send</button>
  </div>
</div>
<div class="deferred-banner" style="margin-top:16px">
  ⬡ Live Claude API integration — every answer is grounded in your current stock health, reorder, expiry and supplier data. Nothing is stored after you leave the page.
</div>
<script>document.addEventListener('DOMContentLoaded',()=>initAIPage('ai-messages'));</script>
<?php render_layout_close(); ?>