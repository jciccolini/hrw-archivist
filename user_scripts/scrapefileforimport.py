#!/usr/bin/env python3
import os
import requests 
import sys 
import glob
import re
import pandas as pd
from urlparse import urljoin
from bs4 import BeautifulSoup
from PyPDF2 import PdfFileReader
reload(sys)
sys.setdefaultencoding('utf8')

#URL to scrape data from coalex site
url = "http://www.osmre.gov/resources/coalex.shtm"

#Put all files in folder. Create folder if there isn't one
folder_location = r'user_files'
if not os.path.exists(folder_location):os.mkdir(folder_location)
#Get and parse html
response = requests.get(url)
soup= BeautifulSoup(response.text, "html.parser")     
for link in soup.select("a[href$='.pdf']"):
    #Name files in ascending order
    filename = os.path.join(folder_location,link['href'].split('/')[-1])
    with open(filename, 'wb') as f:
        f.write(requests.get(urljoin(url,link['href'])).content)

#List all files in folder
pdfs = glob.glob("user_files/coalex_*.pdf")


 #Function to get metadata from downloaded files
def get_info(path):
    with open(path, 'rb') as f:
        filename = re.sub(".*\\/", "",path)
        pdf = PdfFileReader(f)
        info = pdf.getDocumentInfo()
    return([filename, info.author, info.creator, info.producer, info.subject, info.title])

#Create dataframe hardcoded for metadata selected. Change or generalize to pull any other metadata
df = pd.DataFrame(columns=['filename','author','creator','producer','subject','title'])

#Put metadata in dataframe
for pdf in pdfs:
    df.loc[len(df)]=get_info(pdf)


#Write file to path specified
outname = "coalex_import_df.csv"
outdir = "user_files"
if not os.path.exists(outdir):
    os.mkdir(outdir)
path = os.path.join(outdir, outname)
pd.DataFrame.to_csv(df, path_or_buf=path, encoding='utf8', sep='\t')
      