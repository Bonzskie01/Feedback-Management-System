<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['participant_id'], $_SESSION['campaign_id'])) redirect('../index.php');
$participantId = (int) $_SESSION['participant_id'];
$campaignId = (int) $_SESSION['campaign_id'];
$result = pg_query_params($conn,
    "SELECT p.status AS participant_status, p.full_name, p.participant_code, p.group_name, c.*,
            (c.status = 'active' AND (c.start_at IS NULL OR c.start_at <= CURRENT_TIMESTAMP)
             AND (c.end_at IS NULL OR c.end_at >= CURRENT_TIMESTAMP)) AS campaign_accepting
       FROM feedback_participants p JOIN feedback_campaigns c ON c.id = p.campaign_id
      WHERE p.id = $1 AND c.id = $2", [$participantId, $campaignId]);
if (!$result || pg_num_rows($result) !== 1) redirect('../logout.php');
$campaign = pg_fetch_assoc($result);
if ($campaign['participant_status'] === 'responded') redirect('thank_you.php?already=1');
$open = $campaign['campaign_accepting'] === 't';
$blocked = '';
if (!$open) {
    http_response_code(403);
    $blocked = $campaign['status'] !== 'active' ? 'This feedback campaign is not active.' : 'This feedback campaign is outside its scheduled response period. Check the start and end time in Campaigns.';
}
$questionResult = pg_query_params($conn, 'SELECT * FROM feedback_questions WHERE campaign_id=$1 ORDER BY display_order,id', [$campaignId]);
$questions = [];
if ($questionResult) while ($question = pg_fetch_assoc($questionResult)) $questions[] = $question;
$requiredCount = count(array_filter($questions, fn($question) => $question['is_required'] === 't'));
$error = $_GET['error'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($campaign['title']) ?> · Feedback</title>
    <link rel="icon" href="../<?= h(site_favicon()) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="../assets/css/feedback.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>(function(){if(localStorage.getItem('vms-theme')==='dark')document.documentElement.setAttribute('data-theme','dark')})()</script>
</head>
<body class="feedback-ballot vms-participant" style="--feedback-bg:url('../<?= h(site_background()) ?>')">
<div class="participant-wrapper"><div class="participant-container">
    <header class="participant-topbar">
        <div class="participant-info">
            <span class="participant-badge"><i class="fas fa-user-check"></i> <?= h($campaign['full_name']) ?></span>
            <span><i class="fas fa-id-card"></i> <?= h($campaign['participant_code']) ?></span>
            <?php if ($campaign['group_name'] !== ''): ?><span class="participant-group"><i class="fas fa-users"></i> <?= h($campaign['group_name']) ?></span><?php endif; ?>
            <?php if ($campaign['end_at']): ?><span class="participant-end"><i class="fas fa-clock"></i> Ends: <?= h(date('M d, Y h:i A', strtotime($campaign['end_at']))) ?></span><?php endif; ?>
        </div>
        <div class="participant-tools">
            <button class="participant-theme-btn" type="button" onclick="toggleFeedbackTheme()"><i class="fas fa-moon" id="participantThemeIcon"></i> <span>Dark/Light</span></button>
            <a href="#" class="participant-logout" onclick="confirmParticipantLogout(event)"><i class="fas fa-sign-out-alt"></i> Exit</a>
        </div>
    </header>

    <section class="participant-banner">
        <div class="participant-banner-icon"><i class="fas fa-comments"></i></div>
        <h1><?= h($campaign['title']) ?></h1>
        <p><?= $campaign['description'] !== '' ? nl2br(h($campaign['description'])) : 'Share your feedback thoughtfully — your account can submit only once.' ?></p>
    </section>

    <?php if ($blocked): ?><div class="participant-alert error"><i class="fas fa-circle-exclamation"></i><span><?= h($blocked) ?></span></div><?php elseif ($error): ?><div class="participant-alert error"><i class="fas fa-circle-exclamation"></i><span><?= h($error) ?></span></div><?php endif; ?>

    <section class="participant-stats" aria-label="Feedback overview">
        <div class="participant-stat"><div class="participant-stat-number"><?= count($questions) ?></div><div class="participant-stat-label">Questions</div></div>
        <div class="participant-stat"><div class="participant-stat-number"><?= $requiredCount ?></div><div class="participant-stat-label">Required answers</div></div>
        <div class="participant-stat"><div class="participant-stat-number participant-live"><?= $open ? '● LIVE' : '● CLOSED' ?></div><div class="participant-stat-label">Campaign status</div></div>
    </section>

    <?php if (!$blocked): ?><form method="post" action="submit.php" id="feedbackForm"><?= csrf_field() ?>
        <?php if (!$questions): ?>
            <div class="position-card participant-empty"><i class="fas fa-circle-question"></i><h2>No questions available</h2><p>The administrator has not added questions to this campaign.</p></div>
        <?php else: foreach ($questions as $index => $question):
            $id = (int) $question['id']; $required = $question['is_required'] === 't'; $type = $question['question_type']; ?>
            <section class="position-card feedback-position-card">
                <div class="position-title"><span class="question-number"><?= $index + 1 ?></span><span><?= h($question['prompt']) ?><?php if ($required): ?> <span class="required">*</span><?php endif; ?></span></div>
                <div class="position-desc">
                    <?php if ($type === 'rating'): ?><i class="fas fa-star"></i> Select one rating from 1 (lowest) to 5 (highest)
                    <?php elseif ($type === 'single_choice'): ?><i class="fas fa-circle-dot"></i> Select one answer
                    <?php elseif ($type === 'multiple_choice'): ?><i class="fas fa-square-check"></i> Select all answers that apply
                    <?php else: ?><i class="fas fa-pen"></i> Write your response below<?php endif; ?>
                    <?php if (!$required): ?><span class="optional-label">Optional</span><?php endif; ?>
                </div>
                <?php if ($type === 'rating'): ?>
                    <div class="vms-rating-grid"><?php for ($rating = 1; $rating <= 5; $rating++): ?>
                        <label class="vms-answer-card rating-answer"><input type="radio" name="answers[<?= $id ?>]" value="<?= $rating ?>" <?= $required ? 'required' : '' ?>><span class="rating-value"><?= $rating ?></span><span class="rating-stars"><?php for ($star = 1; $star <= $rating; $star++): ?><i class="fas fa-star"></i><?php endfor; ?></span></label>
                    <?php endfor; ?></div>
                <?php elseif ($type === 'text'): ?>
                    <div class="vms-text-answer"><textarea name="answers[<?= $id ?>]" maxlength="5000" <?= $required ? 'required' : '' ?> placeholder="Share your thoughts…"></textarea><div class="text-counter"><span>0</span>/5000</div></div>
                <?php else: $options = json_decode($question['options'], true) ?: []; ?>
                    <div class="vms-choice-grid"><?php foreach ($options as $option): ?>
                        <label class="vms-answer-card choice-answer"><input type="<?= $type === 'multiple_choice' ? 'checkbox' : 'radio' ?>" name="answers[<?= $id ?>]<?= $type === 'multiple_choice' ? '[]' : '' ?>" value="<?= h($option) ?>" <?= ($required && $type === 'single_choice') ? 'required' : '' ?>><span class="answer-check"><i class="fas fa-check"></i></span><span><?= h($option) ?></span></label>
                    <?php endforeach; ?></div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
            <div class="participant-submit-section"><button class="participant-submit" type="submit"><i class="fas fa-check-circle"></i> Submit My Feedback</button><p><i class="fas fa-lock"></i> Your response is secure and cannot be changed after submission.</p></div>
        <?php endif; ?>
    </form><?php endif; ?>
    <footer class="participant-footer">Feedback Management System &copy; <?= date('Y') ?> · <?= h(site_name()) ?></footer>
</div></div>
<script>
function participantSwalTheme(){const dark=document.documentElement.getAttribute('data-theme')==='dark';return{background:dark?'#0f1e4a':'#fff',color:dark?'#f1f5f9':'#1e293b'}}
function updateParticipantThemeIcon(){const icon=document.getElementById('participantThemeIcon');if(icon)icon.className=document.documentElement.getAttribute('data-theme')==='dark'?'fas fa-sun':'fas fa-moon'}
function toggleFeedbackTheme(){const root=document.documentElement,dark=root.getAttribute('data-theme')==='dark';if(dark){root.removeAttribute('data-theme');localStorage.setItem('vms-theme','light')}else{root.setAttribute('data-theme','dark');localStorage.setItem('vms-theme','dark')}updateParticipantThemeIcon()}
function confirmParticipantLogout(event){event.preventDefault();if(typeof Swal==='undefined'){if(confirm('Exit your feedback session?'))location.href='../logout.php';return}Swal.fire({...participantSwalTheme(),title:'Exit feedback session?',text:'Your unsent answers will not be saved.',icon:'question',showCancelButton:true,confirmButtonColor:'#ef4444',confirmButtonText:'Yes, exit',cancelButtonText:'Continue answering'}).then(result=>{if(result.isConfirmed)location.href='../logout.php'})}
document.querySelectorAll('.vms-text-answer textarea').forEach(textarea=>{const counter=textarea.parentElement.querySelector('.text-counter span');const update=()=>counter.textContent=textarea.value.length;textarea.addEventListener('input',update);update()});
const feedbackForm=document.getElementById('feedbackForm');if(feedbackForm)feedbackForm.addEventListener('submit',function(event){if(this.dataset.confirmed==='1')return;event.preventDefault();if(typeof Swal==='undefined'){if(confirm('Submit your feedback? This cannot be undone.')){this.dataset.confirmed='1';this.requestSubmit()}return}Swal.fire({...participantSwalTheme(),title:'Submit Your Feedback?',text:'This action cannot be undone. You will not be able to submit again.',icon:'warning',showCancelButton:true,confirmButtonColor:'#2e7d32',cancelButtonColor:'#c62828',confirmButtonText:'Yes, submit my feedback',cancelButtonText:'Review answers'}).then(result=>{if(result.isConfirmed){this.dataset.confirmed='1';this.requestSubmit()}})});
updateParticipantThemeIcon();
</script>
</body></html>
