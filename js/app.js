/**
 * app.js - Frontend Logic for Inbox Module
 * Implements Context JS principles for real-time interactions
 */

document.addEventListener('DOMContentLoaded', () => {
	console.log("Inbox module initialized");

	// Simple event listeners for the mockup interactions
	
	// 1. Mailbox navigation
	const folders = document.querySelectorAll('.folder-list li');
	folders.forEach(folder => {
		folder.addEventListener('click', (e) => {
			folders.forEach(f => f.classList.remove('active'));
			e.currentTarget.classList.add('active');
			// In real version, fetch emails via Ajax
			console.log("Switching folder...");
		});
	});

	// 2. Email selection
	const emails = document.querySelectorAll('.email-item');
	emails.forEach(email => {
		email.addEventListener('click', (e) => {
			emails.forEach(em => em.classList.remove('active'));
			e.currentTarget.classList.add('active');
			e.currentTarget.classList.remove('unread');
			
			// Show view panel (useful for mobile responsiveness later)
			document.getElementById('panel-view').style.display = 'flex';
			
			// Fetch email details via Ajax
			console.log("Loading email content...");
		});
	});

	// 3. Comment submission
	const commentBtn = document.querySelector('.comment-input-area .btn-primary');
	const commentTextarea = document.querySelector('.comment-input-area textarea');
	
	if (commentBtn && commentTextarea) {
		commentBtn.addEventListener('click', () => {
			const text = commentTextarea.value.trim();
			if (text) {
				// Fake adding comment
				const commentsList = document.querySelector('.comments-list');
				const newComment = document.createElement('div');
				newComment.className = 'comment-item';
				newComment.innerHTML = `
					<div class="comment-avatar">Me</div>
					<div class="comment-content">
						<div class="comment-meta"><span class="comment-author">Current User</span> <span class="comment-date">Maintenant</span></div>
						<div class="comment-text">"${text}"</div>
					</div>
				`;
				commentsList.appendChild(newComment);
				commentTextarea.value = '';
			}
		});
	}
});
