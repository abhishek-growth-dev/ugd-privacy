"""Run against a disposable local database/server, never production.

CARVAULT_TEST_URL=http://127.0.0.1:8088 python3 tests/reservations_integration.py
Creates temporary users and cars in the local test database.
"""
import concurrent.futures
import http.cookiejar
import os
import re
import time
import urllib.parse
import urllib.request

BASE = os.environ.get('CARVAULT_TEST_URL', 'http://127.0.0.1:8088')
assert urllib.parse.urlparse(BASE).hostname in ('127.0.0.1', 'localhost'), 'Local test server only'
stamp = str(time.time_ns())


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = ''

    def get(self, path='?'):
        r = self.opener.open(BASE + '/' + path, timeout=15)
        text = r.read().decode()
        found = re.search(r'name="csrf" value="([^"]+)"', text)
        if found:
            self.token = found.group(1)
        assert 'Fatal error' not in text and 'Warning:' not in text
        return r.url, text

    def post(self, path, **data):
        data.setdefault('csrf', self.token)
        r = self.opener.open(BASE + '/' + path, urllib.parse.urlencode(data).encode(), timeout=15)
        text = r.read().decode()
        found = re.search(r'name="csrf" value="([^"]+)"', text)
        if found:
            self.token = found.group(1)
        assert 'Fatal error' not in text and 'Warning:' not in text
        return r.url, text

    def account(self, role):
        self.get('?p=register')
        email = role + stamp + '@example.test'
        self.post('?p=register', action='register', name=role.title()+' Test', email=email, password='Test!23456', confirm='Test!23456')
        url, text = self.post('?p=login', action='login', email=email, password='Test!23456')
        assert 'p=user_panel' in url and 'Your user panel' in text


owner, buyer, other, guest = [Client() for _ in range(4)]
owner.account('owner')
buyer.account('buyer')
other.account('other')
assert 'p=login' in guest.get('?p=user_panel')[0]
owner.get('?p=add')
car_name = 'Reservation Test '+stamp
owner.post('?p=add', action='save_car', car_name=car_name, brand='Test Brand', model='Test Model', year=2024, color='Blue', fuel_type='Petrol', price=100000, description='PRIVATE_NOTE_'+stamp)
_, collection = owner.get('?p=collection')
car_id = re.search(r'p=view&id=(\d+)', collection).group(1)
assert car_name not in guest.get('?p=browse')[1]
other.post('?p=user_panel', action='set_listing', id=car_id, listed=1)
assert car_name not in guest.get('?p=browse')[1]
owner.post('?p=view&id='+car_id, action='set_listing', id=car_id, listed=1, csrf='invalid')
assert car_name not in guest.get('?p=browse')[1]
owner.post('?p=view&id='+car_id, action='set_listing', id=car_id, listed=1)
_, public = guest.get('?p=reserve&id='+car_id)
assert car_name in public and 'PRIVATE_NOTE_'+stamp not in public
assert 'next_car='+car_id in public
_, login = guest.get('?p=login&next_car='+car_id)
assert 'p=register&next_car='+car_id in login
assert 'cannot reserve your own' in owner.post('?p=reserve&id='+car_id, action='reserve_car', id=car_id)[1]

# Two independent customers racing for the same real listing must produce one request.
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    results = list(pool.map(lambda c: c.post('?p=reserve&id='+car_id, action='reserve_car', id=car_id), [buyer, other]))
assert sum('Reservation request submitted.' in t for _, t in results) == 1
winner, loser = (buyer, other) if 'Reservation request submitted.' in results[0][1] else (other, buyer)
_, panel = winner.get('?p=user_panel')
assert car_name in panel and 'Pending' in panel
reservation_id = re.search(r'name="action" value="cancel_reservation"><input type="hidden" name="id" value="(\d+)"', panel).group(1)
reference = re.search(r'CV-[A-F0-9]{12}', panel).group(0)
assert reference not in loser.get('?p=user_panel')[1]
loser.post('?p=user_panel', action='cancel_reservation', id=reservation_id)
assert 'Pending' in winner.get('?p=user_panel')[1]
winner.post('?p=user_panel', action='review_reservation', id=reservation_id, status='confirmed')
assert 'Pending' in winner.get('?p=user_panel')[1]
owner.post('?p=view&id='+car_id, action='delete_car', id=car_id)
assert car_name in owner.get('?p=collection')[1]
owner.post('?p=user_panel&tab=requests', action='review_reservation', id=reservation_id, status='confirmed')
assert 'Confirmed' in winner.get('?p=user_panel')[1]
winner.post('?p=user_panel', action='cancel_reservation', id=reservation_id)
assert 'Cancelled' in winner.get('?p=user_panel')[1]
assert 'Reservation request submitted.' in loser.post('?p=reserve&id='+car_id, action='reserve_car', id=car_id)[1]
_, panel2 = loser.get('?p=user_panel')
second = re.search(r'name="action" value="cancel_reservation"><input type="hidden" name="id" value="(\d+)"', panel2).group(1)
owner.post('?p=user_panel', action='review_reservation', id=second, status='declined')
assert 'Declined' in loser.get('?p=user_panel')[1]
owner.post('?p=view&id='+car_id, action='delete_car', id=car_id)
assert car_name not in owner.get('?p=collection')[1]
assert reference in winner.get('?p=user_panel')[1]  # History survives car removal.

# Demo bookings do not consume real inventory and are private to the requester.
_, catalog = guest.get('?p=browse')
demo_id = re.search(r'p=reserve&id=(\d+)', catalog).group(1)
assert 'Demo reservation saved' in buyer.post('?p=reserve&id='+demo_id, action='reserve_car', id=demo_id)[1]
assert 'already have an active demo' in buyer.post('?p=reserve&id='+demo_id, action='reserve_car', id=demo_id)[1]
assert 'Demo reservation saved' in other.post('?p=reserve&id='+demo_id, action='reserve_car', id=demo_id)[1]
print('PASS: authentication, private listings/notes, CSRF, ownership, concurrent booking, persistence, owner approval, cancellation, decline, deletion/history and demo isolation.')
