#!/usr/bin/env python
from datetime import datetime
from pymongo import MongoClient
from time import sleep
import requests
from pprint import pprint
from random import randint
import sys

con = MongoClient('web')
col = con.pybot.log
total = col.count()
def main() :
    endpoint = 'https://serveo.us/pybotv5/push'
    while True :
        try :
            rand = randint(0, total)
            data = col.find().skip(rand).limit(1)
            for record in data :
                created = record['time']
                dt = datetime.fromtimestamp(created)
                date = dt.strftime('%Y-%m-%d %H:%M:%S')
                message = "%s : <%s> : %s" % (date, record['user'], record['message'])
            params = {
                'user' : 'otherbot',
                'channel' : '#to-talk-history',
                'message' : message
            }
            requests.post(endpoint, data=params)
        except :
            pass
            
        sleep(30)

if __name__ == '__main__' :
    try :
        main()
    except KeyboardInterrupt :
        sys.exit(1)
