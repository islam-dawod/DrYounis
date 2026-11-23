/* CRM dashboard client — renders the table and handles actions.
   Data is injected by /crm/index.php into <script id="crmdata">. */
(function () {
  var D = JSON.parse(document.getElementById('crmdata').textContent);
  var ST = D.statuses, CSRF = D.csrf, NOTES = D.notes || {}, WL = D.waLabels || {};
  var app = document.getElementById('app');
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); };

  function post(data) {
    data.csrf = CSRF;
    return fetch('/crm/index.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data) }).then(function (r) { return r.json(); });
  }
  function waIntl(p) {
    var d = String(p || '').replace(/\D/g, '');
    if (!d) return '';
    if (d.indexOf('00') === 0) d = d.slice(2);
    if (d.indexOf('972') === 0) return d;
    if (d[0] === '0') return '972' + d.slice(1);
    if (d.length === 9 && d[0] === '5') return '972' + d;
    return d;
  }
  var WA_SVG = '<svg class="waico" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 00-8.5 15.2L2 22l4.9-1.4A10 10 0 1012 2zm5.3 14.1c-.2.6-1.2 1.2-1.7 1.2-.5.1-1 .1-1.6-.1-.4-.1-.9-.3-1.5-.6-2.6-1.1-4.3-3.8-4.4-4-.1-.2-1-1.4-1-2.6s.6-1.8.9-2.1c.2-.2.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.7 1.8c.1.2 0 .4-.1.5l-.3.4c-.1.2-.3.3-.1.6.1.3.7 1.1 1.4 1.7.9.8 1.7 1 2 1.2.2.1.4 0 .5-.1l.6-.7c.2-.2.4-.2.6-.1l1.7.8c.2.1.4.2.4.3.1.1.1.6-.1 1z"/></svg>';
  var CALL_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M6.6 10.8a15 15 0 006.6 6.6l2.2-2.2a1 1 0 011-.24 11 11 0 003.5.56 1 1 0 011 1V20a1 1 0 01-1 1A17 17 0 013 4a1 1 0 011-1h3.5a1 1 0 011 1 11 11 0 00.56 3.5 1 1 0 01-.24 1z"/></svg>';
  var BOT_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="8" width="16" height="11" rx="3"/><path d="M12 8V4M9 3h6"/><circle cx="9" cy="13.5" r="1.1" fill="currentColor" stroke="none"/><circle cx="15" cy="13.5" r="1.1" fill="currentColor" stroke="none"/></svg>';

  var today = new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Jerusalem' }).slice(0, 10);
  var countToday = 0, countNew = 0;
  D.leads.forEach(function (l) { if ((l.ts || '').indexOf(today) === 0) countToday++; if ((l.status || 'new') === 'new') countNew++; });

  function stOptions(sel) {
    return Object.keys(ST).map(function (k) { return '<option value="' + k + '"' + (sel === k ? ' selected' : '') + '>' + esc(ST[k]) + '</option>'; }).join('');
  }

  function noteHTML(n) {
    return '<div class="note" data-nid="' + esc(n.id) + '"><div class="nhead"><span class="nt">' + esc(n.t) + '</span>' +
      (n.e ? '<span class="ned">(נערך ' + esc(n.e) + ')</span>' : '') +
      '<span class="nsp"></span><button type="button" class="nact" data-act="edit" title="עריכה">✎</button>' +
      '<button type="button" class="nact" data-act="del" title="מחיקה">🗑</button></div><div class="nx">' + esc(n.txt) + '</div></div>';
  }

  function rowHTML(l) {
    var ns = NOTES[l.id] || [];
    var ntext = ns.map(function (n) { return n.txt; }).join(' ');
    var st = l.status || 'new';
    var wl = WL[l.id] || '';
    var phone = l.phone || '';
    var tel = phone.replace(/[^0-9+]/g, '');
    var tr = '<tr class="lead' + (ns.length ? ' has-notes' : '') + '" data-id="' + esc(l.id) + '" data-status="' + esc(st) + '" data-notes="' + esc(ntext) + '"' +
      ' data-name="' + esc(l.name) + '" data-phone="' + esc(phone) + '" data-email="' + esc(l.email) + '" data-interest="' + esc(l.interest) +
      '" data-city="' + esc(l.city) + '" data-fit="' + esc(l.fit) + '" data-question="' + esc(l.question) + '" data-msg="' + esc(l.msg) + '" data-source="' + esc(l.source) + '" data-consent="' + (l.consent ? 1 : 0) + '">';
    tr += '<td class="acts"><button type="button" class="edt" data-id="' + esc(l.id) + '">✎ עריכה</button><button type="button" class="del" data-id="' + esc(l.id) + '">🗑 מחיקה</button></td>';
    tr += '<td style="white-space:nowrap">' + esc(l.ts) + '</td>';
    tr += '<td>' + esc(l.name) + '</td>';
    tr += '<td style="white-space:nowrap">' + (phone ? '<a class="lnk walnk" dir="ltr" target="_blank" rel="noopener" href="https://wa.me/' + esc(waIntl(phone)) + '">' + WA_SVG + esc(phone) + '</a>' +
      '<a class="callbtn" dir="ltr" href="tel:' + esc(tel) + '" title="התקשרות">' + CALL_SVG + '</a>' +
      '<button type="button" class="botbtn' + (wl ? ' sent' : '') + '" data-id="' + esc(l.id) + '" data-consent="' + (l.consent ? 1 : 0) + '" title="שליחת הודעת בוט ב־WhatsApp">' + BOT_SVG + '</button>' +
      '<span class="wast" data-id="' + esc(l.id) + '">' + esc(wl) + '</span>' : '') + '</td>';
    tr += '<td>' + (l.email ? '<a class="lnk" dir="ltr" href="mailto:' + esc(l.email) + '">' + esc(l.email) + '</a>' : '') + '</td>';
    tr += '<td class="city">' + esc(l.city) + '</td>';
    tr += '<td class="itr">' + esc(l.interest) + '</td>';
    tr += '<td class="itr">' + esc(l.fit) + '</td>';
    tr += '<td class="msg">' + esc(l.question) + '</td>';
    tr += '<td class="msg">' + esc(l.msg) + '</td>';
    tr += '<td class="src">' + esc(l.source) + '</td>';
    tr += '<td><button type="button" class="nbtn">📝 הערות <span class="ncount">' + (ns.length ? '(' + ns.length + ')' : '') + '</span></button></td>';
    tr += '<td><select class="st st-' + esc(st) + '" data-id="' + esc(l.id) + '">' + stOptions(st) + '</select></td>';
    tr += '</tr>';
    var nr = '<tr class="nrow" hidden><td colspan="13"><div class="notes"><div class="nlist">' +
      (ns.length ? ns.map(noteHTML).join('') : '<div class="nempty">אין הערות עדיין.</div>') +
      '</div><form class="nform" data-id="' + esc(l.id) + '"><textarea rows="2" placeholder="הוסיפו הערה…"></textarea><button type="submit">שמירת הערה</button><span class="nmsg"></span></form></div></td></tr>';
    return tr + nr;
  }

  function render() {
    var h = '';
    h += '<div class="stats"><div class="stat"><b>' + D.leads.length + '</b><span>סה״כ פניות</span></div>' +
      '<div class="stat"><b>' + countToday + '</b><span>פניות היום</span></div>' +
      '<div class="stat"><b>' + countNew + '</b><span>ממתינות לטיפול</span></div></div>';
    h += '<div class="toolbar"><input type="search" id="q" placeholder="חיפוש לפי שם / טלפון / דוא״ל / עיר / הודעה…">' +
      '<select id="fstatus"><option value="">כל הסטטוסים</option>' + Object.keys(ST).map(function (k) { return '<option value="' + k + '">' + esc(ST[k]) + '</option>'; }).join('') + '</select>' +
      '<a class="btn" href="?export=csv">⬇ ייצוא CSV</a>' +
      '<button type="button" class="btn add" id="newLead">+ ליד חדש</button></div>';
    if (!D.leads.length) { h += '<div class="table-card"><div class="empty">אין עדיין פניות.</div></div>'; }
    else {
      h += '<div class="table-card"><table id="tbl"><thead><tr><th class="acth">פעולות</th><th>תאריך</th><th>שם</th><th>טלפון</th><th>דוא״ל</th><th>עיר</th><th>טיפול</th><th>מה מתאים כרגע?</th><th>שאלה / מידע</th><th>הודעה</th><th>מקור</th><th>הערות</th><th>סטטוס</th></tr></thead><tbody>' +
        D.leads.map(rowHTML).join('') + '</tbody></table></div>';
    }
    h += modalHTML();
    app.innerHTML = h;
    wire();
  }

  function modalHTML() {
    return '<div class="modal" id="leadModal" hidden><div class="mbox"><h3 id="leadTitle">הוספת ליד חדש</h3>' +
      '<form id="leadForm" autocomplete="off"><input type="hidden" name="lead_id" value="">' +
      '<div class="mgrid">' +
      '<label>שם מלא *<input name="name" required></label>' +
      '<label>טלפון *<input name="phone" inputmode="tel" required dir="ltr"></label>' +
      '<label>דוא״ל<input name="email" type="email" dir="ltr"></label>' +
      '<label>טיפול / עניין<input name="interest"></label>' +
      '<label>עיר<input name="city"></label>' +
      '<label>מקור<input name="source" value="הוספה ידנית"></label>' +
      '<label>סטטוס<select name="status">' + stOptions('new') + '</select></label>' +
      '<label>מה מתאים לכם כרגע?<input name="fit"></label>' +
      '</div>' +
      '<label>יש לכם שאלה או מידע?<textarea name="question" rows="2"></textarea></label>' +
      '<label>הודעה<textarea name="msg" rows="2"></textarea></label>' +
      '<label><span id="noteLbl">הערה ראשונה (אופציונלי)</span><textarea name="note" rows="2"></textarea></label>' +
      '<div class="mact"><button type="submit" class="save">הוספת הליד</button><button type="button" class="cancel">ביטול</button><span class="mmsg"></span></div>' +
      '</form></div></div>';
  }

  function applyFilter() {
    var q = (document.getElementById('q').value || '').toLowerCase(), st = document.getElementById('fstatus').value;
    document.querySelectorAll('#tbl tbody tr.lead').forEach(function (tr) {
      var hay = (tr.innerText + ' ' + (tr.getAttribute('data-notes') || '')).toLowerCase();
      var show = (!q || hay.indexOf(q) > -1) && (!st || tr.getAttribute('data-status') === st);
      tr.style.display = show ? '' : 'none';
      var nr = tr.nextElementSibling; if (nr && nr.classList.contains('nrow')) nr.style.display = show ? '' : 'none';
    });
  }

  function lfield(f, n) { return f.querySelector('[name=' + n + ']'); }

  function wire() {
    var q = document.getElementById('q'), fs = document.getElementById('fstatus');
    if (q) q.addEventListener('input', applyFilter);
    if (fs) fs.addEventListener('change', applyFilter);

    // status change
    document.querySelectorAll('select.st').forEach(function (sel) {
      sel.addEventListener('change', function () {
        var tr = sel.closest('tr');
        sel.className = 'st st-' + sel.value; tr.setAttribute('data-status', sel.value);
        post({ action: 'status', id: sel.getAttribute('data-id'), value: sel.value }).then(applyFilter);
      });
    });
    // delete
    document.querySelectorAll('.del').forEach(function (b) {
      b.addEventListener('click', function () {
        var tr = b.closest('tr'), nm = (tr.getAttribute('data-name') || '').trim();
        if (!confirm('למחוק את הליד' + (nm ? ' „' + nm + '”' : '') + ' לצמיתות?\nיימחקו גם ההערות והסטטוס. לא ניתן לשחזר.')) return;
        post({ action: 'delete', id: b.getAttribute('data-id') }).then(function (r) {
          if (!r.ok) return; var nr = tr.nextElementSibling; if (nr && nr.classList.contains('nrow')) nr.remove(); tr.remove();
        });
      });
    });
    // notes open
    document.querySelectorAll('.nbtn').forEach(function (b) {
      b.addEventListener('click', function () {
        var nr = b.closest('tr').nextElementSibling; if (!nr || !nr.classList.contains('nrow')) return;
        nr.hidden = !nr.hidden; if (!nr.hidden) { var ta = nr.querySelector('textarea'); if (ta) ta.focus(); }
      });
    });
    // note add
    document.querySelectorAll('.nform').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        e.preventDefault();
        var id = f.getAttribute('data-id'), ta = f.querySelector('textarea'), btn = f.querySelector('button'), msg = f.querySelector('.nmsg'), txt = (ta.value || '').trim();
        if (!txt) { ta.focus(); return; }
        btn.disabled = true; msg.textContent = 'שומר…';
        post({ action: 'note_add', id: id, text: txt }).then(function (r) {
          btn.disabled = false;
          if (!(r && r.ok)) { msg.textContent = 'שגיאה'; return; }
          var list = f.parentNode.querySelector('.nlist'), em = list.querySelector('.nempty'); if (em) em.remove();
          var d = document.createElement('div'); d.innerHTML = noteHTML(r.note); list.appendChild(d.firstChild);
          ta.value = ''; msg.textContent = 'נשמר ✓'; setTimeout(function () { msg.textContent = ''; }, 1600);
          refreshNotes(list); wireNoteActs();
        });
      });
    });
    wireNoteActs();
    // add/edit lead modal
    var lModal = document.getElementById('leadModal'), lForm = document.getElementById('leadForm'), lId = '';
    document.getElementById('newLead').addEventListener('click', function () { openLead(null); });
    document.querySelectorAll('.edt').forEach(function (b) { b.addEventListener('click', function () { openLead(b.closest('tr')); }); });
    lModal.addEventListener('click', function (e) { if (e.target === lModal) lModal.hidden = true; });
    lModal.querySelector('.cancel').addEventListener('click', function () { lModal.hidden = true; });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !lModal.hidden) lModal.hidden = true; });

    function openLead(tr) {
      lId = tr ? tr.getAttribute('data-id') : '';
      var g = function (a) { return tr ? (tr.getAttribute('data-' + a) || '') : ''; };
      document.getElementById('leadTitle').textContent = lId ? 'עריכת פרטי הליד' : 'הוספת ליד חדש';
      lForm.querySelector('.save').textContent = lId ? 'שמירת השינויים' : 'הוספת הליד';
      lForm.querySelector('.save').disabled = false; lForm.querySelector('.mmsg').textContent = '';
      document.getElementById('noteLbl').textContent = lId ? 'הוספת הערה (אופציונלי)' : 'הערה ראשונה (אופציונלי)';
      lfield(lForm, 'lead_id').value = lId;
      ['name', 'phone', 'email', 'interest', 'city', 'fit', 'question', 'msg'].forEach(function (k) { lfield(lForm, k).value = g(k); });
      lfield(lForm, 'source').value = tr ? g('source') : 'הוספה ידנית';
      lfield(lForm, 'note').value = '';
      lfield(lForm, 'status').value = tr ? (tr.getAttribute('data-status') || 'new') : 'new';
      lModal.hidden = false; lfield(lForm, 'name').focus();
    }
    lForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = lForm.querySelector('.save'), msg = lForm.querySelector('.mmsg'), data = { action: lId ? 'lead_edit' : 'lead_add' };
      if (lId) data.lead_id = lId;
      ['name', 'phone', 'email', 'interest', 'city', 'fit', 'question', 'source', 'status', 'msg', 'note'].forEach(function (k) { var el = lfield(lForm, k); data[k] = el ? el.value.trim() : ''; });
      if (!data.name) { msg.textContent = 'נא למלא שם'; return; }
      if (data.phone.replace(/\D/g, '').length < 7) { msg.textContent = 'טלפון לא תקין'; return; }
      btn.disabled = true; msg.textContent = 'שומר…';
      post(data).then(function (r) {
        if (r && r.ok) { msg.textContent = 'נשמר ✓'; location.reload(); return; }
        btn.disabled = false;
        msg.textContent = (r && r.error === 'bad_phone') ? 'טלפון לא תקין' : (r && r.error === 'no_name') ? 'נא למלא שם' : (r && r.error === 'not_found') ? 'הליד לא נמצא — רעננו' : 'שגיאה';
      });
    });
    // bot send
    document.querySelectorAll('.botbtn').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-id'), consent = b.getAttribute('data-consent') === '1', st = document.querySelector('.wast[data-id="' + id + '"]');
        if (!consent && !confirm('לא תועד אישור WhatsApp לליד זה. לשלוח בכל זאת את הודעת הבוט?')) return;
        if (consent && !confirm('לשלוח לליד את הודעת הפתיחה של הבוט ב־WhatsApp?')) return;
        b.disabled = true; if (st) st.textContent = 'שולח…';
        post({ action: 'wa_send', id: id, confirm: consent ? '0' : '1' }).then(function (r) {
          if (r && r.ok) { b.classList.add('sent'); if (st) st.textContent = r.label || 'נשלח'; }
          else { b.disabled = false; if (st) st.textContent = (r && r.label) ? r.label : waErr(r && r.error); }
        }).catch(function () { b.disabled = false; if (st) st.textContent = 'שגיאה'; });
      });
    });
  }

  function waErr(e) {
    var m = { not_configured: 'הבוט לא מוגדר', bad_phone: 'טלפון לא תקין', lead_not_found: 'הליד לא נמצא', fetch_failed: 'כשל חיבור', recently_contacted: 'כבר נשלח ב־24ש׳', not_in_pilot: 'לא ברשימת הבדיקה' };
    return (e && m[e]) ? m[e] : 'שגיאה';
  }

  function refreshNotes(list) {
    var nrow = list.closest('tr.nrow'); if (!nrow) return;
    var tr = nrow.previousElementSibling; if (!tr) return;
    var txts = []; list.querySelectorAll('.note .nx').forEach(function (x) { txts.push(x.textContent); });
    var c = tr.querySelector('.ncount'); if (c) c.textContent = txts.length ? '(' + txts.length + ')' : '';
    tr.setAttribute('data-notes', txts.join(' '));
    if (txts.length) tr.classList.add('has-notes'); else tr.classList.remove('has-notes');
    if (!txts.length && !list.querySelector('.nempty')) { var em = document.createElement('div'); em.className = 'nempty'; em.textContent = 'אין הערות עדיין.'; list.appendChild(em); }
  }

  function wireNoteActs() {
    document.querySelectorAll('.nact').forEach(function (b) {
      if (b._wired) return; b._wired = true;
      b.addEventListener('click', function () {
        var note = b.closest('.note'), list = note.parentNode, nrow = note.closest('tr.nrow'),
          form = nrow.querySelector('.nform'), lid = form.getAttribute('data-id'), nid = note.getAttribute('data-nid');
        if (b.getAttribute('data-act') === 'del') {
          if (!confirm('למחוק הערה זו?')) return;
          post({ action: 'note_del', id: lid, nid: nid }).then(function (r) { if (r && r.ok) { note.remove(); refreshNotes(list); } });
          return;
        }
        if (note.querySelector('.nedit')) return;
        var body = note.querySelector('.nx'), box = document.createElement('div'), ta = document.createElement('textarea'),
          ok = document.createElement('button'), no = document.createElement('button'), msg = document.createElement('span');
        box.className = 'nedit'; ta.rows = 3; ta.value = body.textContent;
        ok.type = 'button'; ok.className = 'nsave'; ok.textContent = 'שמירה'; no.type = 'button'; no.className = 'ncancel'; no.textContent = 'ביטול'; msg.className = 'nmsg';
        box.appendChild(ta); box.appendChild(ok); box.appendChild(no); box.appendChild(msg);
        body.style.display = 'none'; note.appendChild(box); ta.focus();
        no.addEventListener('click', function () { box.remove(); body.style.display = ''; });
        ok.addEventListener('click', function () {
          var txt = (ta.value || '').trim(); if (!txt) { ta.focus(); return; }
          ok.disabled = true; msg.textContent = 'שומר…';
          post({ action: 'note_edit', id: lid, nid: nid, text: txt }).then(function (r) {
            ok.disabled = false;
            if (!(r && r.ok)) { msg.textContent = 'שגיאה'; return; }
            body.textContent = r.note.txt;
            var hd = note.querySelector('.nhead'), ed = note.querySelector('.ned');
            if (!ed) { ed = document.createElement('span'); ed.className = 'ned'; hd.insertBefore(ed, note.querySelector('.nsp')); }
            ed.textContent = '(נערך ' + (r.note.e || '') + ')';
            box.remove(); body.style.display = '';
          });
        });
      });
    });
  }

  render();
})();
